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
 *    youngest first), waiting_since (asc: longest waiting first), nominated_at (asc).
 *
 * Ties are always broken by the longest wait, then the nomination. Exam results join the rules once the exam step
 * records them.
 */
final class InterviewQueue
{
    public const MAX_RULES = 10;

    private const PREFERENCES = [
        'specialization_id', 'job_position_id', 'nominating_entity_id', 'application_category_id',
        'academic_qualification_id', 'residence_district_id', 'residence_area_id',
    ];

    /** Value rules: name => [SQL expression, default direction]. */
    private const VALUES = [
        'quran_parts' => ['applications.quran_parts_memorized', 'desc'],
        'studying_years' => ['applications.studying_years', 'desc'],
        // Youngest first is the latest birth date first.
        'age' => ['(select c.birth_date from candidates c where c.id = applications.candidate_id)', 'asc', true],
        'waiting_since' => ['waiting_since', 'asc'],
        'nominated_at' => ['applications.created_at', 'asc'],
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

    /** @param array<string, mixed> $filters validated with rules() */
    public static function query(array $filters): Builder
    {
        $query = Application::query()
            ->select('applications.*')
            ->selectSub(fn($sub) => $sub->from('application_status_events as e')
                ->selectRaw('max(e.occurred_at)')
                ->whereColumn('e.application_id', 'applications.id')
                ->where('e.to_status', ApplicationStatus::AwaitingInterview->value), 'waiting_since')
            ->where('applications.current_status', ApplicationStatus::AwaitingInterview)
            ->whereNotExists(fn($interview) => $interview->from('interviews')
                ->whereColumn('interviews.application_id', 'applications.id')
                ->where('interviews.status', InterviewStatus::Scheduled->value));

        ApplicationSearch::filter($query, $filters);

        foreach (array_values((array) ($filters['priority'] ?? [])) as $index => $rule) {
            self::order($query, (string) $rule, "priority.{$index}");
        }

        return $query->orderBy('waiting_since')->orderBy('applications.created_at')->orderBy('applications.id');
    }

    private static function order(Builder $query, string $rule, string $field): void
    {
        [$name, $argument] = array_pad(explode(':', trim($rule), 2), 2, null);

        if (in_array($name, self::PREFERENCES, true)) {
            $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $argument)), fn($id) => $id !== '')));

            if ($ids === [] || count($ids) > 50 || array_filter($ids, fn($id) => ! ctype_digit($id)) !== []) {
                self::fail($field, 'interviews.priority_needs_ids', ['rule' => $name]);
            }

            $cases = implode(' ', array_map(fn($position) => "WHEN ? THEN {$position}", array_keys($ids)));
            $query->orderByRaw("CASE applications.{$name} {$cases} ELSE " . count($ids) . ' END', array_map('intval', $ids));

            return;
        }

        if (! isset(self::VALUES[$name]) || ! in_array($argument, [null, 'asc', 'desc'], true)) {
            self::fail($field, 'interviews.priority_unknown', ['rule' => $rule]);
        }

        [$expression, $default] = self::VALUES[$name];
        $direction = $argument ?? $default;
        // Age runs opposite to the birth date.
        $sqlDirection = $name === 'age' ? ($direction === 'asc' ? 'desc' : 'asc') : $direction;

        $query->orderByRaw("{$expression} {$sqlDirection} nulls last");
    }

    private static function fail(string $field, string $key, array $replace): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
