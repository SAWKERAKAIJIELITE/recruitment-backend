<?php

namespace App\Modules\Recruitment\Queries;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\AppointmentCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The appointment an application waits for, and whether Recruitment told the candidate of it (D-105): Recruitment is
 * the only office that talks to the candidates (D-086), so each booking of an exam or an interview is for it a person
 * to call. For information only: it never moves the application and never blocks anything (an absence still goes on
 * the booking, and an excuse brings the candidate back).
 *
 * The appointment ahead: an exam booking still `booked` whose session's late window has not closed (awaiting_exam),
 * or a `scheduled` interview whose time has not come (awaiting_interview). Its call state is read from the latest call
 * about it as it stands now (the same time and place): `informed`, `not_reached` (no answer), or `to_inform` (no call
 * yet, or only about a time or place that has changed since).
 */
final class Appointments
{
    public const STATES = ['to_inform', 'not_reached', 'informed'];

    public const STATE_LABELS = ['to_inform' => 'بانتظار الإعلام', 'not_reached' => 'لم يُتوصَّل إليه', 'informed' => 'أُعلم بالموعد'];

    public const KIND_LABELS = ['exam' => 'اختبار', 'interview' => 'مقابلة'];

    /**
     * The SQL of an application's call state (null when it has no appointment ahead), for `applications` rows. Takes
     * the bindings [$now, $now].
     */
    public static function stateSql(): string
    {
        $state = fn(string $calls) => "CASE ({$calls}) WHEN true THEN 'informed' WHEN false THEN 'not_reached' ELSE 'to_inform' END";
        $exam = $state('SELECT c.reached FROM appointment_calls c WHERE c.exam_booking_id = b.id AND c.appointment_at = s.starts_at AND c.location = s.location ORDER BY c.id DESC LIMIT 1');
        $interview = $state('SELECT c.reached FROM appointment_calls c WHERE c.interview_id = i.id AND c.appointment_at = i.scheduled_at AND c.location = st.location ORDER BY c.id DESC LIMIT 1');

        return "(SELECT state FROM (
                SELECT {$exam} AS state FROM exam_bookings b JOIN exam_sessions s ON s.id = b.session_id
                WHERE b.application_id = applications.id AND b.status = 'booked' AND applications.current_status = 'awaiting_exam'
                AND s.starts_at + s.late_minutes * interval '1 minute' > ?
                UNION ALL
                SELECT {$interview} FROM interviews i JOIN interview_sittings st ON st.id = i.sitting_id
                WHERE i.application_id = applications.id AND i.status = 'scheduled' AND applications.current_status = 'awaiting_interview'
                AND i.scheduled_at > ?
            ) appointment LIMIT 1)";
    }

    /**
     * The appointment ahead of each application, with its call state and its last call; with $withCalls every call
     * about it (older times included).
     *
     * @param  Collection<int, Application>  $applications
     * @return array<string, array> by application id (only those with an appointment ahead)
     */
    public static function of(Collection $applications, CarbonInterface $now, bool $withCalls = false): array
    {
        $ids = $applications->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $exams = DB::table('exam_bookings as b')
            ->join('exam_sessions as s', 's.id', '=', 'b.session_id')
            ->join('applications as a', 'a.id', '=', 'b.application_id')
            ->whereIn('b.application_id', $ids)
            ->where('b.status', 'booked')
            ->where('a.current_status', 'awaiting_exam')
            ->whereRaw("s.starts_at + s.late_minutes * interval '1 minute' > ?", [$now])
            ->get(['b.application_id', 'b.id as exam_booking_id', 's.id as session_id', 's.starts_at as at', 's.location']);
        $interviews = DB::table('interviews as i')
            ->join('interview_sittings as st', 'st.id', '=', 'i.sitting_id')
            ->join('applications as a', 'a.id', '=', 'i.application_id')
            ->whereIn('i.application_id', $ids)
            ->where('i.status', 'scheduled')
            ->where('a.current_status', 'awaiting_interview')
            ->where('i.scheduled_at', '>', $now)
            ->get(['i.application_id', 'i.id as interview_id', 'st.id as sitting_id', 'i.scheduled_at as at', 'st.location']);

        $appointments = [];

        foreach ($exams as $row) {
            $appointments[$row->application_id] = ['kind' => 'exam', 'exam_booking_id' => $row->exam_booking_id, 'session_id' => $row->session_id, 'interview_id' => null, 'sitting_id' => null, 'at' => $row->at, 'location' => $row->location];
        }

        foreach ($interviews as $row) {
            $appointments[$row->application_id] = ['kind' => 'interview', 'exam_booking_id' => null, 'session_id' => null, 'interview_id' => $row->interview_id, 'sitting_id' => $row->sitting_id, 'at' => $row->at, 'location' => $row->location];
        }

        $calls = AppointmentCall::query()
            ->with('caller')
            ->where(fn($query) => $query
                ->whereIn('exam_booking_id', array_filter(array_column($appointments, 'exam_booking_id')))
                ->orWhereIn('interview_id', array_filter(array_column($appointments, 'interview_id'))))
            ->orderBy('id')
            ->get()
            ->groupBy(fn(AppointmentCall $call) => $call->exam_booking_id ?? $call->interview_id);

        $shown = [];

        foreach ($appointments as $applicationId => $appointment) {
            $at = Carbon::parse($appointment['at']);
            $own = $calls->get($appointment['exam_booking_id'] ?? $appointment['interview_id'], collect());
            $current = $own->filter(fn(AppointmentCall $call) => $call->appointment_at->equalTo($at) && $call->location === $appointment['location'])->last();
            $state = $current === null ? 'to_inform' : ($current->reached ? 'informed' : 'not_reached');

            $shown[$applicationId] = [
                /** exam or interview. */
                'kind' => $appointment['kind'],
                'kind_label' => self::KIND_LABELS[$appointment['kind']],
                'exam_booking_id' => $appointment['exam_booking_id'],
                'session_id' => $appointment['session_id'],
                'interview_id' => $appointment['interview_id'],
                'sitting_id' => $appointment['sitting_id'],
                'at' => $at->toIso8601String(),
                'location' => $appointment['location'],
                /** to_inform, not_reached or informed (of this time and place). */
                'call_state' => $state,
                'call_state_label' => self::STATE_LABELS[$state],
                /** The latest call about this booking or interview, of this time and place or an earlier one (`about`). */
                'last_call' => $own->isEmpty() ? null : self::call($own->last()),
            ] + ($withCalls ? ['calls' => $own->map(fn(AppointmentCall $call) => self::call($call))->values()->all()] : []);
        }

        return $shown;
    }

    /** @return array{at: string, by: ?array{id: string, full_name: string}, reached: bool, note: ?string, about: array{at: string, location: string}} */
    public static function call(AppointmentCall $call): array
    {
        return [
            'at' => $call->created_at->toIso8601String(),
            'by' => $call->caller === null ? null : ['id' => $call->caller->id, 'full_name' => $call->caller->full_name],
            'reached' => $call->reached,
            'note' => $call->note,
            /** The time and place the candidate was told. */
            'about' => ['at' => $call->appointment_at->toIso8601String(), 'location' => $call->location],
        ];
    }
}
