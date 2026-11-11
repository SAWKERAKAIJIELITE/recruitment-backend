<?php

namespace App\Modules\Interviews\Queries;

use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Who is waiting to be booked for an interview, in the order the staff choose (owner: "the interviews are scheduled
 * through priorities determined by staff: specializations, residency, exam results, ..."; D-062).
 *
 * The queue is every application awaiting its interview with no interview booked. It takes the filters of the
 * applications list, and `priority`: an ordered list of rules, the first deciding first:
 *
 *  - a list with the entries to put first, in order: `specialization_id:5,2` (specialization 5, then 2, then the
 *    rest). Also job_position_id, nominating_entity_id, application_category_id, academic_qualification_id,
 *    residence_district_id, residence_area_id;
 *  - a value, `:asc` or `:desc`: quran_parts (default desc: most parts first), studying_years (desc), age (asc:
 *    youngest first), waiting_since (asc: longest waiting first), nominated_at (asc), exam_average (desc: the best
 *    exam first; applications without a recorded exam result come last), excused (desc: back after an accepted
 *    interview absence excuse first; asc puts them last).
 *
 * Ties are always broken by the longest wait, then the nomination.
 *
 * Each entry's `queue_position` is its place in the whole queue in that order, counted before the filters (D-094):
 * with a job position filter, the first row shows how many wait ahead of it in every position.
 */
final class InterviewQueue
{
    public const MAX_RULES = 10;

    /** When it last became awaiting_interview. */
    private const WAITING_SINCE = "(select max(e.occurred_at) from application_status_events e where e.application_id = applications.id and e.to_status = 'awaiting_interview')";

    private const PREFERENCES = [
        'specialization_id',
        'job_position_id',
        'nominating_entity_id',
        'application_category_id',
        'academic_qualification_id',
        'residence_district_id',
        'residence_area_id',
    ];

    /** Value rules: name => [SQL expression, default direction]. */
    private const VALUES = [
        'quran_parts' => ['applications.quran_parts_memorized', 'desc'],
        'studying_years' => ['applications.studying_years', 'desc'],
        // Youngest first is the latest birth date first.
        'age' => ['(select c.birth_date from candidates c where c.id = applications.candidate_id)', 'asc', true],
        'waiting_since' => [self::WAITING_SINCE, 'asc'],
        // The exam average of the result that admitted it (its latest round and revision; none for older outcomes).
        'exam_average' => ['(select r.average from exam_results r join exam_attempts t on t.id = r.attempt_id join exam_bookings b on b.id = t.booking_id where b.application_id = applications.id order by b.round desc, r.revision desc limit 1)', 'desc'],
        'nominated_at' => ['applications.created_at', 'asc'],
        // Back in the queue through an accepted interview excuse: the latest event (none follows it) is that excuse.
        'excused' => ["exists (select 1 from application_status_events e where e.application_id = applications.id and e.decision_type = 'excuse_accepted' and not exists (select 1 from application_status_events n where n.previous_event_id = e.id))", 'desc'],
    ];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $rules = array_diff_key(ApplicationSearch::rules(), array_flip(['status', 'status.*', 'live', 'sort', 'direction']));

        return $rules + [
            'priority' => ['nullable', 'array', 'max:' . self::MAX_RULES],
            'priority.*' => ['string', 'max:300'],
        ];
    }

    /** The rule names and what each takes, for the documentation and the frontend. */
    public static function ruleNames(): array
    {
        return ['lists' => self::PREFERENCES, 'values' => array_keys(self::VALUES)];
    }

    /**
     * The whole queue is numbered in the priority order first (`queue_position`), then the filters choose the rows:
     * the positions stay those of the whole queue.
     *
     * @param  array<string, mixed>  $filters  validated with rules()
     */
    public static function query(array $filters): Builder
    {
        $orders = [];

        foreach (array_values((array) ($filters['priority'] ?? [])) as $index => $rule) {
            $orders[] = self::order((string) $rule, "priority.{$index}");
        }

        $orders = [...$orders, [self::WAITING_SINCE . ' asc', []], ['applications.created_at asc', []], ['applications.id asc', []]];

        $queue = Application::query()
            ->select('applications.*')
            ->selectRaw(self::WAITING_SINCE . ' as waiting_since')
            ->selectRaw('row_number() over (order by ' . implode(', ', array_column($orders, 0)) . ') as queue_position', array_merge(...array_column($orders, 1)))
            ->where('applications.current_status', ApplicationStatus::AwaitingInterview)
            ->whereNotExists(fn($interview) => $interview->from('interviews')
                ->whereColumn('interviews.application_id', 'applications.id')
                ->where('interviews.status', InterviewStatus::Scheduled->value));

        $query = Application::query()->fromSub($queue, 'applications')->select('applications.*');
        ApplicationSearch::filter($query, $filters);

        return $query->orderBy('applications.queue_position');
    }

    /** @return array{0: string, 1: list<int>} one rule as SQL to order by, with its bindings */
    private static function order(string $rule, string $field): array
    {
        [$name, $argument] = array_pad(explode(':', trim($rule), 2), 2, null);

        if (in_array($name, self::PREFERENCES, true)) {
            $ids = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'trim',
                            explode(',', (string) $argument)
                        ),
                        fn($id) => $id !== ''
                    )
                )
            );

            if ($ids === [] || count($ids) > 50 || array_filter($ids, fn($id) => ! ctype_digit($id)) !== []) {
                self::fail($field, 'interviews.priority_needs_ids', ['rule' => $name]);
            }

            $cases = implode(' ', array_map(fn($position) => "WHEN ? THEN {$position}", array_keys($ids)));

            return ["CASE applications.{$name} {$cases} ELSE " . count($ids) . ' END', array_map('intval', $ids)];
        }

        if (! isset(self::VALUES[$name]) || ! in_array($argument, [null, 'asc', 'desc'], true)) {
            self::fail($field, 'interviews.priority_unknown', ['rule' => $rule]);
        }

        [$expression, $default] = self::VALUES[$name];
        $direction = $argument ?? $default;
        // Age runs opposite to the birth date.
        $sqlDirection = $name === 'age' ? ($direction === 'asc' ? 'desc' : 'asc') : $direction;

        return ["{$expression} {$sqlDirection} nulls last", []];
    }

    private static function fail(string $field, string $key, array $replace): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
