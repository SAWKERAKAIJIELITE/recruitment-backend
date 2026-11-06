<?php

namespace App\Modules\Reports\Queries;

use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\PassPolicy;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The marks behind exam decisions, for the staff exams report (legacy exams sheet: a mark per subject, the total, the
 * percentage and the failure cause; D-080): the latest revision of each booking's result. Outcomes recorded without
 * an exam session (older history) have none.
 */
final class ExamScores
{
    /**
     * @param  list<string>  $bookingIds
     * @return array{scores: array<string, array{subjects: array<int, string>, total: string, average: string, passed: bool, cause: ?string}>, subjects: array<int, string>}
     *                                                                                                                                                                       scores by booking id; subjects (id => name) in the order the plans list them
     */
    public static function forBookings(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return ['scores' => [], 'subjects' => []];
        }

        $rows = DB::table('exam_results as r')
            ->join('exam_attempts as t', 't.id', '=', 'r.attempt_id')
            ->whereIn('t.booking_id', array_values(array_unique($bookingIds)))
            ->whereRaw('r.revision = (select max(x.revision) from exam_results x where x.attempt_id = r.attempt_id)')
            ->get(['t.booking_id', 'r.passed', 'r.average', 'r.outcome']);

        $outcomes = $rows->mapWithKeys(fn ($row) => [$row->booking_id => ['passed' => (bool) $row->passed, 'average' => (string) $row->average, 'outcome' => json_decode($row->outcome, true)]]);
        $order = [];

        foreach ($outcomes as $result) {
            foreach ($result['outcome']['subjects'] as $subject) {
                $order[$subject['subject_id']] ??= count($order);
            }
        }

        $names = ExamSubject::withTrashed()->whereIn('id', array_keys($order))->pluck('name_ar', 'id');
        $policies = PassPolicy::query()->get()->keyBy('version');
        $scores = [];

        foreach ($outcomes as $bookingId => $result) {
            $subjects = collect($result['outcome']['subjects'])->mapWithKeys(fn (array $subject) => [$subject['subject_id'] => $subject['score']])->all();

            $scores[$bookingId] = [
                'subjects' => $subjects,
                'total' => self::sum($subjects),
                'average' => $result['average'],
                'passed' => $result['passed'],
                'cause' => self::cause($result['outcome'], $names->all(), $policies->get($result['outcome']['policy_version'] ?? 0)),
            ];
        }

        return ['scores' => $scores, 'subjects' => collect($order)->sort()->keys()->mapWithKeys(fn (int $id) => [$id => $names[$id] ?? (string) $id])->all()];
    }

    /** Subject scores are shown with two decimals; their total is added on the shown values, like the sheet. */
    private static function sum(array $subjects): string
    {
        return (string) array_reduce($subjects, fn (BigDecimal $total, string $score) => $total->plus($score), BigDecimal::of('0.00'));
    }

    /** 58.00 → 58, 70.50 → 70.5 */
    private static function short(?string $value): ?string
    {
        return $value === null ? null : rtrim(rtrim($value, '0'), '.');
    }

    /** Why it failed, in the words of the pass rules (null when passed). */
    private static function cause(array $outcome, array $names, ?PassPolicy $policy): ?string
    {
        if (($outcome['passed'] ?? false) || ($outcome['reasons'] ?? []) === []) {
            return null;
        }

        $subjects = fn (array $reason) => implode('، ', array_map(fn (int $id) => $names[$id] ?? (string) $id, $reason['subject_ids'] ?? []));

        return implode(' — ', array_map(fn (array $reason) => __("reports.failure_causes.{$reason['code']}", [
            'subjects' => $subjects($reason),
            'floor' => self::short($policy?->tolerance_floor),
            'mark' => self::short($policy?->subject_pass_mark),
            'average' => self::short($policy?->average_must_exceed),
        ], 'ar'), $outcome['reasons']));
    }
}
