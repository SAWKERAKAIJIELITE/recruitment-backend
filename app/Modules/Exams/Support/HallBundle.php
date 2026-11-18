<?php

namespace App\Modules\Exams\Support;

use App\Modules\Exams\Models\ExamHallDownload;
use App\Modules\Exams\Models\ExamSession;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * What a laptop brings back from a hall (D-102), checked against what it took out, before anything is written.
 *
 * The bundle is a JSON text, sealed by the laptop with its device key: the upload sends the text exactly as it was
 * sealed and the signature of "recruitment-hall-exam-v1\n" followed by that text (ECDSA P-256 with SHA-256, DER,
 * base64), like the device login. Its shape:
 *
 *     {
 *       "version": 1, "download_id": "…", "session_id": "…",
 *       "started_at": "2026-11-21T10:40:00+03:00",       the hall's real start (the planned one when on time)
 *       "start_changed_reason": null | "…",                needed when the real start is not the planned one
 *       "sealed_at": "…",                                  when the laptop sealed it, at the exam's end
 *       "committee": ["…", "…"],                           who signed the paper attendance sheet (1 to 10)
 *       "attendance_count": 28,                            signatures on it: one per candidate who sat
 *       "extensions": [{"minutes": 10, "reason": "…", "at": "…"}],     for everyone, in time order
 *       "bookings": [
 *         {"booking_id": "…", "attempt": null},                          did not come
 *         {"booking_id": "…", "attempt": {
 *            "started_at": "…", "submitted_at": "…", "closed_by": "candidate" | "timeout",
 *            "extensions": [{"minutes": 5, "reason": "…", "at": "…"}],   this candidate's own, in time order
 *            "receipt": "4F2A-9C11-B07E",
 *            "items": [{"position": 1, "session_question_id": 9007,
 *                       "revisions": [{"selected_option": 1, "saved_at": "…"}, {"selected_option": null, "saved_at": "…"}]}]
 *         }}
 *       ]
 *     }
 *
 * Times are ISO 8601 with an offset (Z or ±hh:mm), counted to the second. The hall keeps the same rules as the server:
 * a start from the session's start until its late window closes; every attempt ends with the session (start +
 * duration + the extensions for everyone given before it started), moved by the extensions given while it ran; a
 * timed-out attempt closes at its deadline exactly; a chosen option is the last save of that question.
 */
final class HallBundle
{
    public const PAYLOAD_PREFIX = 'recruitment-hall-exam-v1';

    private const MAX_ERRORS = 30;

    private const MAX_EXTENSIONS = 20;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** Every problem found, also past the 30 listed. */
    private int $failures = 0;

    /**
     * @param  array<int, int>  $optionCounts  how many options each of the session's questions has, by session question id
     */
    public function __construct(
        private readonly ExamHallDownload $download,
        private readonly ExamSession $session,
        private readonly array $optionCounts,
        private readonly CarbonInterface $now,
    ) {}

    /** What the laptop signs: the prefix, a newline, the bundle's text exactly as uploaded. */
    public static function payload(string $bundle): string
    {
        return self::PAYLOAD_PREFIX . "\n" . $bundle;
    }

    /**
     * @return array{
     *   started_at: CarbonImmutable, start_changed_reason: ?string, sealed_at: CarbonImmutable, committee: list<string>,
     *   attendance_count: int, extensions: list<array{minutes: int, reason: string, at: CarbonImmutable}>, has_extensions: bool,
     *   bookings: list<array{booking_id: string, attempt: ?array}>
     * }
     *
     * @throws ValidationException listing every problem found (up to 30), keyed by its place in the bundle
     */
    public function check(array $doc): array
    {
        if (($doc['version'] ?? null) !== 1) {
            $this->fail('bundle.version', 'version');
        }

        if (($doc['session_id'] ?? null) !== $this->session->id) {
            $this->fail('bundle.session_id', 'wrong_session');
        }

        $planned = CarbonImmutable::instance($this->session->starts_at)->startOfSecond();
        $start = $this->time($doc, 'started_at', 'bundle.started_at');
        $sealed = $this->time($doc, 'sealed_at', 'bundle.sealed_at');
        $reason = $this->text($doc['start_changed_reason'] ?? null, 500, 'bundle.start_changed_reason', nullable: true);

        if ($start !== null) {
            if ($start->lessThan($planned)) {
                $this->fail('bundle.started_at', 'start_early', ['time' => $planned->toIso8601String()]);
            } elseif ($start->setTimezone(BusinessDay::timezone())->toDateString() !== $planned->setTimezone(BusinessDay::timezone())->toDateString()) {
                $this->fail('bundle.started_at', 'start_other_day');
            } elseif (! $start->equalTo($planned) && $reason === null) {
                $this->fail('bundle.start_changed_reason', 'start_reason');
            }
        }

        $committee = $this->committee($doc['committee'] ?? null);
        $attendance = $doc['attendance_count'] ?? null;

        if (! is_int($attendance) || $attendance < 0) {
            $this->fail('bundle.attendance_count', 'attendance_count');
        }

        if ($start === null || $sealed === null) {
            $this->throw();   // the rest is measured from the start
        }

        $duration = $this->session->duration_minutes + $this->session->extra_minutes;
        $lateUntil = $start->addMinutes($this->session->late_minutes);
        $extensions = $this->sessionExtensions($doc['extensions'] ?? null, $start, $duration);
        $bookings = $this->bookings($doc['bookings'] ?? null, $start, $lateUntil, $duration, $extensions, $sealed);
        $sat = count(array_filter($bookings, fn(array $booking) => $booking['attempt'] !== null));

        if (is_int($attendance) && $attendance !== $sat) {
            $this->fail('bundle.attendance_count', 'attendance', ['sat' => $sat, 'signed' => $attendance]);
        }

        $latest = max([$start, ...array_column($extensions, 'at'), ...array_map(fn(array $booking) => $booking['attempt']['last'] ?? $start, $bookings)]);

        if ($sealed->lessThan($latest)) {
            $this->fail('bundle.sealed_at', 'sealed_early');
        }

        if ($sealed->greaterThan($this->now)) {
            $this->fail('bundle.sealed_at', 'future');
        }

        $this->throw();

        return [
            'started_at' => $start,
            'start_changed_reason' => $start->equalTo($planned) ? null : $reason,
            'sealed_at' => $sealed,
            'committee' => $committee,
            'attendance_count' => $attendance,
            'extensions' => $extensions,
            'has_extensions' => $extensions !== [] || array_filter($bookings, fn(array $booking) => ($booking['attempt']['own_extensions'] ?? 0) > 0) !== [],
            'bookings' => $bookings,
        ];
    }

    /** @return list<array{minutes: int, reason: string, at: CarbonImmutable}> */
    private function sessionExtensions(mixed $list, CarbonImmutable $start, int $duration): array
    {
        $extensions = [];
        $previous = $start;
        $end = $start->addMinutes($duration);

        foreach ($this->list($list, 'bundle.extensions', self::MAX_EXTENSIONS) as $index => $item) {
            $extension = $this->extension($item, "bundle.extensions.{$index}");

            if ($extension === null) {
                continue;
            }

            if ($extension['at']->lessThan($previous)) {
                $this->fail("bundle.extensions.{$index}.at", 'extension_order');
            } elseif ($extension['at']->greaterThanOrEqualTo($end)) {
                $this->fail("bundle.extensions.{$index}.at", 'extension_ended');   // given after everyone's time was over
            }

            $previous = $extension['at'];
            $end = $end->addMinutes($extension['minutes']);
            $extensions[] = $extension;
        }

        return $extensions;
    }

    /** @return list<array{booking_id: string, attempt: ?array}> */
    private function bookings(mixed $list, CarbonImmutable $start, CarbonImmutable $lateUntil, int $duration, array $extensions, CarbonImmutable $sealed): array
    {
        $expected = array_flip($this->download->booking_ids);
        $seen = [];
        $bookings = [];

        foreach ($this->list($list, 'bundle.bookings', count($expected) + 1) as $index => $item) {
            $path = "bundle.bookings.{$index}";
            $id = is_array($item) ? ($item['booking_id'] ?? null) : null;

            if (! is_string($id) || ! isset($expected[$id])) {
                $this->fail("{$path}.booking_id", 'booking_not_downloaded');

                continue;
            }

            if (isset($seen[$id])) {
                $this->fail("{$path}.booking_id", 'booking_twice');

                continue;
            }

            $seen[$id] = true;

            if (! array_key_exists('attempt', $item) || ($item['attempt'] !== null && ! is_array($item['attempt']))) {
                $this->fail("{$path}.attempt", 'attempt_shape');

                continue;
            }

            $attempt = $item['attempt'] === null ? null : $this->attempt($item['attempt'], $id, "{$path}.attempt", $start, $lateUntil, $duration, $extensions, $sealed);
            $bookings[] = ['booking_id' => $id, 'attempt' => $attempt];
        }

        $missing = count(array_diff_key($expected, $seen));

        if ($missing > 0) {
            $this->fail('bundle.bookings', 'bookings_missing', ['count' => $missing]);
        }

        return $bookings;
    }

    private function attempt(array $doc, string $bookingId, string $path, CarbonImmutable $start, CarbonImmutable $lateUntil, int $duration, array $sessionExtensions, CarbonImmutable $sealed): ?array
    {
        $errors = $this->failures;
        $startedAt = $this->time($doc, 'started_at', "{$path}.started_at");
        $submittedAt = $this->time($doc, 'submitted_at', "{$path}.submitted_at");
        $closedBy = $doc['closed_by'] ?? null;

        if (! in_array($closedBy, ['candidate', 'timeout'], true)) {
            $this->fail("{$path}.closed_by", 'closed_by');
        }

        if ($startedAt !== null && ($startedAt->lessThan($start) || $startedAt->greaterThanOrEqualTo($lateUntil))) {
            $this->fail("{$path}.started_at", 'start_window', ['from' => $start->toIso8601String(), 'until' => $lateUntil->toIso8601String()]);
        }

        if ($startedAt === null || $submittedAt === null || $this->failures > $errors) {
            return null;
        }

        // The deadline, as the server would have moved it: the session's end at the start, then each extension
        // given while the attempt ran (for everyone, or its own), in time order.
        $deadline = $start->addMinutes($duration);
        $moves = [];

        foreach ($sessionExtensions as $index => $extension) {
            if ($extension['at']->lessThanOrEqualTo($startedAt)) {
                $deadline = $deadline->addMinutes($extension['minutes']);   // given before this candidate started
            } else {
                $moves[] = ['session_extension' => $index] + $extension;
            }
        }

        foreach ($this->list($doc['extensions'] ?? [], "{$path}.extensions", self::MAX_EXTENSIONS) as $index => $item) {
            $extension = $this->extension($item, "{$path}.extensions.{$index}");

            if ($extension !== null) {
                $moves[] = ['session_extension' => null, 'path' => "{$path}.extensions.{$index}"] + $extension;
            }
        }

        // In time order; at the same moment, the one for everyone first.
        usort($moves, fn(array $a, array $b) => [$a['at']->getTimestamp(), $a['session_extension'] === null] <=> [$b['at']->getTimestamp(), $b['session_extension'] === null]);
        $applied = [];
        $initial = $deadline;

        foreach ($moves as $move) {
            $running = $move['at']->greaterThanOrEqualTo($startedAt) && $move['at']->lessThan($deadline)
                && ($closedBy === 'timeout' || $move['at']->lessThan($submittedAt));

            if (! $running) {
                if ($move['session_extension'] === null) {
                    $this->fail("{$move['path']}.at", 'own_extension_closed');   // more time for an attempt that was over
                }

                continue;   // everyone's extension came after this candidate was done
            }

            $applied[] = ['previous' => $deadline, 'new' => $deadline->addMinutes($move['minutes'])] + $move;
            $deadline = $deadline->addMinutes($move['minutes']);
        }

        if ($closedBy === 'timeout' && ! $submittedAt->equalTo($deadline)) {
            $this->fail("{$path}.submitted_at", 'timeout_time', ['time' => $deadline->toIso8601String()]);
        } elseif ($submittedAt->lessThan($startedAt) || $submittedAt->greaterThan($deadline)) {
            $this->fail("{$path}.submitted_at", 'submitted_window', ['time' => $deadline->toIso8601String()]);
        }

        $items = $this->items($doc['items'] ?? null, "{$path}.items", $startedAt, $submittedAt);

        if ($this->failures > $errors) {
            return null;
        }

        $receipt = HallReceipt::code($bookingId, $submittedAt->getTimestamp(), array_map(fn(array $item) => ['position' => $item['position'], 'session_question_id' => $item['session_question_id'], 'option' => $item['option']], $items));

        if (! is_string($doc['receipt'] ?? null) || HallReceipt::normalize($doc['receipt']) !== $receipt) {
            $this->fail("{$path}.receipt", 'receipt');
        }

        return [
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'closed_by' => $closedBy,
            'initial_deadline' => $initial,
            'deadline' => $deadline,
            'moves' => $applied,
            'own_extensions' => count(array_filter($applied, fn(array $move) => $move['session_extension'] === null)),
            'own_minutes' => array_sum(array_map(fn(array $move) => $move['session_extension'] === null ? $move['minutes'] : 0, $applied)),
            'receipt' => $receipt,
            'items' => $items,
            'last' => $submittedAt,
        ];
    }

    /** @return list<array{position: int, session_question_id: int, option: ?int, answered_at: ?CarbonImmutable, revisions: list<array{option: ?int, saved_at: CarbonImmutable}>}> */
    private function items(mixed $list, string $path, CarbonImmutable $startedAt, CarbonImmutable $submittedAt): array
    {
        $count = count($this->optionCounts);
        $before = $this->failures;
        $items = [];
        $positions = [];
        $questions = [];

        foreach ($this->list($list, $path, $count) as $index => $item) {
            $at = "{$path}.{$index}";
            $position = is_array($item) ? ($item['position'] ?? null) : null;
            $question = is_array($item) ? ($item['session_question_id'] ?? null) : null;

            if (! is_int($position) || $position < 1 || $position > $count || isset($positions[$position])) {
                $this->fail("{$at}.position", 'item_position', ['count' => $count]);

                continue;
            }

            if (! is_int($question) || ! isset($this->optionCounts[$question]) || isset($questions[$question])) {
                $this->fail("{$at}.session_question_id", 'item_question');

                continue;
            }

            $positions[$position] = true;
            $questions[$question] = true;
            $revisions = [];
            $previous = $startedAt;

            foreach ($this->list($item['revisions'] ?? null, "{$at}.revisions", 1000) as $number => $revision) {
                $option = is_array($revision) && array_key_exists('selected_option', $revision) ? $revision['selected_option'] : false;
                $savedAt = is_array($revision) ? $this->time($revision, 'saved_at', "{$at}.revisions.{$number}.saved_at") : null;

                if ($option !== null && (! is_int($option) || $option < 0 || $option >= $this->optionCounts[$question])) {
                    $this->fail("{$at}.revisions.{$number}.selected_option", 'option', ['count' => $this->optionCounts[$question]]);

                    continue;
                }

                if ($savedAt === null) {
                    continue;
                }

                if ($savedAt->lessThan($previous) || $savedAt->greaterThan($submittedAt)) {
                    $this->fail("{$at}.revisions.{$number}.saved_at", 'revision_time');

                    continue;
                }

                $previous = $savedAt;
                $revisions[] = ['option' => $option, 'saved_at' => $savedAt];
            }

            $last = $revisions === [] ? null : $revisions[array_key_last($revisions)];
            $items[$position] = [
                'position' => $position,
                'session_question_id' => $question,
                'option' => $last['option'] ?? null,
                'answered_at' => $last !== null && $last['option'] !== null ? $last['saved_at'] : null,
                'revisions' => $revisions,
            ];
        }

        if (count($items) !== $count && $this->failures === $before) {
            $this->fail($path, 'items_count', ['count' => $count]);   // every question of the session, once
        }

        ksort($items);

        return array_values($items);
    }

    /** @return ?array{minutes: int, reason: string, at: CarbonImmutable} */
    private function extension(mixed $item, string $path): ?array
    {
        if (! is_array($item)) {
            $this->fail($path, 'extension');

            return null;
        }

        $minutes = $item['minutes'] ?? null;
        $at = $this->time($item, 'at', "{$path}.at");
        $reason = $this->text($item['reason'] ?? null, 500, "{$path}.reason");

        if (! is_int($minutes) || $minutes < 1 || $minutes > (int) config('exams.max_extension_minutes')) {
            $this->fail("{$path}.minutes", 'extension_minutes', ['max' => (int) config('exams.max_extension_minutes')]);

            return null;
        }

        if ($at === null || $reason === null) {
            return null;
        }

        return ['minutes' => $minutes, 'reason' => $reason, 'at' => $at];
    }

    /** @return list<string> */
    private function committee(mixed $names): array
    {
        if (! is_array($names) || ! array_is_list($names) || count($names) < 1 || count($names) > 10) {
            $this->fail('bundle.committee', 'committee');

            return [];
        }

        $committee = [];

        foreach ($names as $index => $name) {
            $text = $this->text($name, 100, "bundle.committee.{$index}");

            if ($text !== null && mb_strlen($text) < 2) {
                $this->fail("bundle.committee.{$index}", 'committee');
            } elseif ($text !== null) {
                $committee[] = $text;
            }
        }

        return $committee;
    }

    private function list(mixed $value, string $path, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'list');

            return [];
        }

        if (count($value) > $max) {
            $this->fail($path, 'list_long', ['max' => $max]);

            return [];
        }

        return $value;
    }

    private function time(array $doc, string $key, string $path): ?CarbonImmutable
    {
        $value = $doc[$key] ?? null;

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            $this->fail($path, 'time_format');

            return null;
        }

        try {
            $time = CarbonImmutable::parse($value)->utc()->startOfSecond();
        } catch (\Throwable) {
            $this->fail($path, 'time_format');

            return null;
        }

        if ($time->greaterThan($this->now)) {
            $this->fail($path, 'future');

            return null;
        }

        return $time;
    }

    private function text(mixed $value, int $max, string $path, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }

        $text = is_string($value) ? trim($value) : '';

        if ($text === '' && $nullable) {
            return null;
        }

        if ($text === '' || mb_strlen($text) > $max) {
            $this->fail($path, 'text', ['max' => $max]);

            return null;
        }

        return $text;
    }

    private function fail(string $path, string $key, array $replace = []): void
    {
        $this->failures++;

        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[$path][] = __("exams.hall.bundle.{$key}", $replace);
        }
    }

    private function throw(): void
    {
        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }
    }
}
