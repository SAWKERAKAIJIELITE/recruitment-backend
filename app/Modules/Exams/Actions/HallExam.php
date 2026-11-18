<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Enums\ClosedBy;
use App\Modules\Exams\Enums\HallDownloadStatus;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamAttemptItem;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamHallDownload;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Exams\Models\ExamSessionQuestion;
use App\Modules\Exams\Support\HallBundle;
use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Support\LifecycleNotices;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exams in a hall with no internet (D-102). The moderator's approved laptop takes a session out before it starts,
 * the candidates sit it there on their phones, and the laptop brings it back:
 *
 *  - download: the bookings that could sit and the drawn questions, never the correct answers; the session is out
 *    and frozen on the server (HallGuard, exam_hall_frozen), its candidates' online tokens are revoked;
 *  - upload: the sealed bundle (HallBundle) is checked against the download, then recorded as if the exam had been
 *    online: attempts with the hall's times marked and decided by the server, absences dated when the late window
 *    closed. All or nothing; the same upload again changes nothing and gives the same answer;
 *  - cancel: with a reason. Before the start the session is back to normal; after it, the downloaded bookings are
 *    cancelled (the candidates return to the queue). Questions possibly seen by others are archived in the bank.
 *
 * Permission exams.offline; extensions given in the hall also need attempts.extend.
 */
class HallExam
{
    public function __construct(
        private readonly ExamResults $results,
        private readonly SweepExamSessions $sweep,
        private readonly DrawSessionQuestions $draw,
        private readonly LifecycleNotices $notices,
    ) {}

    /** @return array<string, mixed> what the laptop needs */
    public function download(ExamSession $session, StaffUser $actor, ?string $deviceId): array
    {
        return DB::transaction(function () use ($session, $actor, $deviceId) {
            // For update: a booking or a change waits for the download, and sees the session out afterwards.
            $session = ExamSession::query()->whereKey($session->id)->lockForUpdate()->with('plan.jobPosition')->firstOrFail();
            $now = Date::now();
            $device = $deviceId === null ? null : StaffDevice::query()->find($deviceId);

            if ($device === null || $device->status !== DeviceStatus::Approved || $device->staff_user_id !== $actor->id) {
                $this->fail('device', 'exams.hall.device_required');
            }

            if ($now->greaterThanOrEqualTo($session->starts_at)) {
                $this->fail('session', 'exams.hall.started');
            }

            $hours = (int) config('exams.hall_download_hours');

            if ($now->diffInMinutes($session->starts_at, false) > $hours * 60) {
                $this->fail('session', 'exams.hall.too_early', ['hours' => $hours, 'time' => $session->starts_at->copy()->subHours($hours)->toIso8601String()]);
            }

            $questions = $session->questions()->with('version')->get();

            if ($questions->isEmpty()) {
                $this->fail('session', 'exams.hall.no_questions');
            }

            $bookings = ExamBooking::query()
                ->where('session_id', $session->id)
                ->where('status', BookingStatus::Booked)
                ->whereHas('application', fn($application) => $application->where('current_status', ApplicationStatus::AwaitingExam)
                    ->whereHas('candidate', fn($candidate) => $candidate->where('is_active', true)))
                ->with('application.candidate')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($bookings->isEmpty()) {
                $this->fail('session', 'exams.hall.no_bookings');
            }

            // Taken out again before the start: the earlier copy no longer counts.
            $replaced = ExamHallDownload::query()->where('session_id', $session->id)->where('status', HallDownloadStatus::Out)->lockForUpdate()->first();
            $replaced?->forceFill(['status' => HallDownloadStatus::Replaced, 'replaced_at' => $now])->save();

            $download = new ExamHallDownload;
            $download->forceFill([
                'session_id' => $session->id,
                'downloaded_by' => $actor->id,
                'staff_device_id' => $device->id,
                'downloaded_at' => $now,
                'booking_ids' => $bookings->pluck('id')->all(),
                'question_ids' => $questions->pluck('id')->all(),
            ])->save();

            // Nobody sits it online now: the session's candidates lose the tokens they may already hold.
            $revoked = DB::table('personal_access_tokens')
                ->whereIn('exam_booking_id', ExamBooking::query()->where('session_id', $session->id)->select('id'))
                ->delete();

            AuditTrail::record('exam_hall.downloaded', $actor, [
                'session_id' => $session->id, 'download_id' => $download->id, 'device_id' => $device->id,
                'bookings' => $bookings->count(), 'questions' => $questions->count(), 'replaced' => $replaced?->id, 'tokens_revoked' => $revoked,
            ]);

            return [
                'download_id' => $download->id,
                'server_time' => $now->toIso8601String(),
                'session' => [
                    'id' => $session->id,
                    'starts_at' => $session->starts_at->toIso8601String(),
                    'late_minutes' => $session->late_minutes,
                    'duration_minutes' => $session->duration_minutes,
                    'extra_minutes' => $session->extra_minutes,
                    'location' => $session->location,
                    'job_position' => $session->plan->jobPosition->name_ar,
                    'plan' => ['id' => $session->plan->id, 'version' => $session->plan->version],
                ],
                'bookings' => $bookings->map(fn(ExamBooking $booking) => [
                    'booking_id' => $booking->id,
                    'national_number' => $booking->application->candidate->national_number,
                    'full_name' => $booking->application->candidate->full_name,
                    'nomination_number' => $booking->application->nomination_number,
                ])->values()->all(),
                // In the session's order; the laptop gives each candidate their own. Never the correct answer.
                'questions' => $questions->map(fn(ExamSessionQuestion $question) => [
                    'session_question_id' => $question->id,
                    'prompt' => $question->version->prompt,
                    'options' => $question->version->options,
                ])->values()->all(),
            ];
        });
    }

    /** @return array<string, mixed> one answer per booking; the same answer to a repeat of the same upload */
    public function upload(ExamSession $session, string $bundle, string $signature, StaffUser $actor, ?string $deviceId): array
    {
        return $this->notices->batch(fn() => DB::transaction(function () use ($session, $bundle, $signature, $actor, $deviceId) {
            $session = ExamSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            try {
                $doc = json_decode($bundle, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->fail('bundle', 'exams.hall.bad_json');
            }

            $downloadId = is_array($doc) ? ($doc['download_id'] ?? null) : null;
            $download = is_string($downloadId) && preg_match('/^[0-9a-f-]{36}$/i', $downloadId)
                ? ExamHallDownload::query()->whereKey($downloadId)->where('session_id', $session->id)->lockForUpdate()->first()
                : null;

            if ($download === null) {
                $this->fail('bundle.download_id', 'exams.hall.unknown_download');
            }

            match ($download->status) {
                HallDownloadStatus::Returned => hash_equals((string) $download->upload_sha256, hash('sha256', $bundle))
                    ? null
                    : $this->fail('bundle.download_id', 'exams.hall.already_returned'),
                HallDownloadStatus::Cancelled => $this->fail('bundle.download_id', 'exams.hall.cancelled'),
                HallDownloadStatus::Replaced => $this->fail('bundle.download_id', 'exams.hall.replaced'),
                HallDownloadStatus::Out => null,
            };

            if ($download->status === HallDownloadStatus::Returned) {
                return $download->upload_summary;   // the same upload again: nothing changes
            }

            // The seal: made by the laptop that took the session out, whichever device uploads it.
            $device = StaffDevice::query()->findOrFail($download->staff_device_id);

            if ($device->status === DeviceStatus::Revoked) {
                $this->fail('signature', 'exams.hall.device_revoked');
            }

            if (! DeviceCrypto::verify($device->public_key, HallBundle::payload($bundle), $signature)) {
                $this->fail('signature', 'exams.hall.seal');
            }

            $optionCounts = ExamSessionQuestion::query()->whereKey($download->question_ids)->with('version')->get()
                ->mapWithKeys(fn(ExamSessionQuestion $question) => [$question->id => count($question->version->options)])->all();
            $now = Date::now();
            $hall = (new HallBundle($download, $session, $optionCounts, $now))->check($doc);

            if ($hall['has_extensions'] && ! $actor->hasPermission('attempts.extend')) {
                $this->fail('bundle.extensions', 'exams.hall.extensions_permission');
            }

            // This transaction brings the download back: the frozen session takes its writes (exam_session_frozen).
            DB::select("SELECT set_config('recruitment.hall_upload', ?, true)", [$download->id]);

            $this->recordStart($session, $download, $hall, $actor);
            $sessionExtensions = $this->recordSessionExtensions($session, $download, $hall['extensions'], $actor);
            $outcomes = [];

            foreach ($hall['bookings'] as $booking) {
                $outcomes[] = $booking['attempt'] === null
                    ? ['booking_id' => $booking['booking_id'], 'outcome' => $this->sweep->markAbsent($booking['booking_id']) ? 'absent' : 'cancelled', 'receipt' => null]
                    : ['booking_id' => $booking['booking_id'], 'outcome' => 'sat', 'receipt' => $this->recordAttempt($session, $download, $booking['booking_id'], $booking['attempt'], $sessionExtensions, $actor)];
            }

            $counts = array_count_values(array_column($outcomes, 'outcome'));
            $summary = [
                'download_id' => $download->id,
                'session_id' => $session->id,
                'received_at' => $now->toIso8601String(),
                'sat' => $counts['sat'] ?? 0,
                'absent' => $counts['absent'] ?? 0,
                'cancelled' => $counts['cancelled'] ?? 0,
                'bookings' => $outcomes,
            ];

            $download->forceFill([
                'status' => HallDownloadStatus::Returned,
                'returned_at' => $now,
                'returned_by' => $actor->id,
                'returned_device_id' => $deviceId,
                'upload_sha256' => hash('sha256', $bundle),
                'upload_summary' => $summary,
                'sealed_at' => $hall['sealed_at'],
                'hall_started_at' => $hall['started_at'],
                'start_changed_reason' => $hall['start_changed_reason'],
                'committee' => $hall['committee'],
                'attendance_count' => $hall['attendance_count'],
            ])->save();

            AuditTrail::record('exam_hall.uploaded', $actor, [
                'session_id' => $session->id, 'download_id' => $download->id, 'device_id' => $deviceId, 'sealed_by_device' => $download->staff_device_id,
                'sat' => $summary['sat'], 'absent' => $summary['absent'], 'cancelled' => $summary['cancelled'],
                'committee' => $hall['committee'], 'attendance_count' => $hall['attendance_count'], 'started_at' => $hall['started_at']->toIso8601String(),
            ]);

            return $summary;
        }));
    }

    /**
     * @return array{download: ExamHallDownload, redrawn: ?bool} redrawn: when the questions were exposed before the start,
     *         whether new ones could be drawn (false: the bank is short, staff must draw before the session)
     */
    public function cancel(ExamSession $session, string $downloadId, string $reason, bool $questionsExposed, StaffUser $actor): array
    {
        return DB::transaction(function () use ($session, $downloadId, $reason, $questionsExposed, $actor) {
            $session = ExamSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $download = ExamHallDownload::query()->whereKey($downloadId)->where('session_id', $session->id)->lockForUpdate()->first();

            if ($download === null || ! $download->isOut()) {
                $this->fail('download_id', 'exams.hall.not_out');
            }

            $now = Date::now();
            $reason = trim($reason);

            // First the "out" state ends, then the session's own records may change.
            $download->forceFill(['status' => HallDownloadStatus::Cancelled, 'cancelled_at' => $now, 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'questions_exposed' => $questionsExposed])->save();
            $cancelled = 0;

            if ($now->greaterThanOrEqualTo($session->starts_at)) {
                // The exam was (or might have been) held, and nothing came back: the candidates did nothing wrong, they
                // return to the queue to be booked again.
                foreach (ExamBooking::query()->whereKey($download->booking_ids)->where('status', BookingStatus::Booked)->orderBy('id')->lockForUpdate()->get() as $booking) {
                    $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => $now, 'cancelled_by' => $actor->id, 'cancel_reason' => __('exams.hall.booking_cancelled', ['reason' => $reason], 'ar')])->save();
                    AuditTrail::record('exam_booking.cancelled', $actor, ['booking_id' => $booking->id, 'reason' => 'hall_cancelled', 'download_id' => $download->id]);
                    $cancelled++;
                }
            }

            $archived = [];
            $redrawn = null;

            if ($questionsExposed) {
                // Possibly seen by someone else: out of the bank (staff may restore one after checking).
                $questionIds = ExamSessionQuestion::query()->whereKey($download->question_ids)->with('version')->get()->pluck('version.question_id')->unique()->values()->all();

                foreach (ExamQuestion::query()->whereKey($questionIds)->get() as $question) {
                    $question->delete();
                    $archived[] = $question->id;
                    AuditTrail::record('exam_question.archived', $actor, ['question_id' => $question->id, 'reason' => 'exposed', 'download_id' => $download->id]);
                }

                if ($now->lessThan($session->starts_at)) {
                    try {
                        $this->draw->draw($session);   // a savepoint: a bank too short keeps the old questions
                        $redrawn = true;
                        AuditTrail::record('exam_session.redrawn', $actor, ['session_id' => $session->id, 'reason' => 'questions_exposed']);
                    } catch (ValidationException) {
                        $redrawn = false;
                    }
                }
            }

            AuditTrail::record('exam_hall.cancelled', $actor, [
                'session_id' => $session->id, 'download_id' => $download->id, 'reason' => $reason, 'after_start' => $now->greaterThanOrEqualTo($session->starts_at),
                'bookings_cancelled' => $cancelled, 'questions_exposed' => $questionsExposed, 'questions_archived' => $archived, 'redrawn' => $redrawn,
            ]);

            return ['download' => $download, 'redrawn' => $redrawn];
        });
    }

    /** A hall that began later than planned: the session's start becomes the real one, the planned one is kept. */
    private function recordStart(ExamSession $session, ExamHallDownload $download, array $hall, StaffUser $actor): void
    {
        if ($hall['start_changed_reason'] === null) {
            return;
        }

        $planned = $session->starts_at->copy();
        $session->forceFill(['planned_starts_at' => $session->planned_starts_at ?? $planned, 'starts_at' => $hall['started_at']])->save();

        AuditTrail::record('exam_session.updated', $actor, [
            'session_id' => $session->id, 'fields' => ['starts_at'], 'download_id' => $download->id,
            'planned' => $planned->toIso8601String(), 'started' => $hall['started_at']->toIso8601String(), 'reason' => $hall['start_changed_reason'],
        ]);
    }

    /** @return list<int> the ids of the recorded extensions, in the bundle's order */
    private function recordSessionExtensions(ExamSession $session, ExamHallDownload $download, array $extensions, StaffUser $actor): array
    {
        $ids = [];

        foreach ($extensions as $extension) {
            $session->forceFill(['extra_minutes' => $session->extra_minutes + $extension['minutes']])->save();
            $ids[] = $session->extensions()->forceCreate(['minutes' => $extension['minutes'], 'reason' => $extension['reason'], 'extended_by' => $actor->id, 'created_at' => $extension['at']])->id;

            AuditTrail::record('exam_session.extended', $actor, ['session_id' => $session->id, 'minutes' => $extension['minutes'], 'reason' => $extension['reason'], 'at' => $extension['at']->toIso8601String(), 'download_id' => $download->id]);
        }

        return $ids;
    }

    /** One candidate's hall attempt, written as the server would have, then closed and decided by the server. */
    private function recordAttempt(ExamSession $session, ExamHallDownload $download, string $bookingId, array $hall, array $sessionExtensions, StaffUser $actor): string
    {
        $booking = ExamBooking::query()->whereKey($bookingId)->lockForUpdate()->with('application')->firstOrFail();

        $attempt = new ExamAttempt;
        $attempt->forceFill([
            'booking_id' => $booking->id,
            'session_id' => $session->id,
            'started_at' => $hall['started_at'],
            'deadline_at' => $hall['initial_deadline'],
            'start_ip' => null,
            'hall_download_id' => $download->id,
            'receipt_code' => $hall['receipt'],
        ])->save();

        ExamAttemptItem::query()->insert(array_map(fn(array $item) => [
            'attempt_id' => $attempt->id,
            'session_id' => $session->id,
            'position' => $item['position'],
            'session_question_id' => $item['session_question_id'],
            'selected_option' => $item['option'],
            'answered_at' => $item['answered_at'],
        ], $hall['items']));

        $itemIds = ExamAttemptItem::query()->where('attempt_id', $attempt->id)->pluck('id', 'position');
        $revisions = [];

        foreach ($hall['items'] as $item) {
            foreach ($item['revisions'] as $revision) {
                $revisions[] = ['attempt_item_id' => $itemIds[$item['position']], 'selected_option' => $revision['option'], 'ip' => null, 'saved_at' => $revision['saved_at']];
            }
        }

        foreach (array_chunk($revisions, 1000) as $chunk) {
            DB::table('exam_answer_revisions')->insert($chunk);
        }

        $booking->forceFill(['status' => BookingStatus::Attended])->save();

        AuditTrail::record('exam_attempt.started', null, [
            'attempt_id' => $attempt->id, 'booking_id' => $booking->id, 'ip' => null, 'deadline' => $hall['initial_deadline']->toIso8601String(),
            'download_id' => $download->id, 'started_at' => $hall['started_at']->toIso8601String(),
        ], $booking->application->candidate_id);

        foreach ($hall['moves'] as $move) {
            $own = $move['session_extension'] === null;
            $attempt->forceFill(['deadline_at' => $move['new']])->save();
            $attempt->extensions()->forceCreate([
                'session_extension_id' => $own ? null : $sessionExtensions[$move['session_extension']],
                'minutes' => $move['minutes'],
                'reason' => $move['reason'],
                'previous_deadline' => $move['previous'],
                'new_deadline' => $move['new'],
                'extended_by' => $actor->id,
                'created_at' => $move['at'],
            ]);

            if ($own) {
                AuditTrail::record('exam_attempt.extended', $actor, ['attempt_id' => $attempt->id, 'minutes' => $move['minutes'], 'reason' => $move['reason'], 'deadline' => $move['new']->toIso8601String(), 'at' => $move['at']->toIso8601String(), 'download_id' => $download->id]);
            }
        }

        if ($hall['own_minutes'] > 0) {
            $attempt->forceFill(['extra_minutes' => $hall['own_minutes']])->save();
        }

        $this->results->close($attempt, $hall['closed_by'] === 'timeout' ? ClosedBy::Timeout : ClosedBy::Candidate, $hall['submitted_at']);

        return $hall['receipt'];
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
