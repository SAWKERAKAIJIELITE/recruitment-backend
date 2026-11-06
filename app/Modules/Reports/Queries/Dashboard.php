<?php

namespace App\Modules\Reports\Queries;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\NominatingEntity;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportPeriod;
use Carbon\CarbonInterface;

/**
 * The indicators of the dashboard (legacy sheet "لوحة التحكم"; analysis doc §16), counted from what happened in the
 * period, each by its own date (see ReportPeriod):
 *  - nominations: applications nominated;
 *  - exams: decisions taken. Takers attended (passed or failed); passes by exception are counted apart, on the day
 *    of the exception, and the failed later admitted by one are shown among the failed; absences, with those whose
 *    excuse was accepted;
 *  - interviews: results recorded, by the day held; absences with their accepted excuses;
 *  - contracts: first contracts by their writing date, renewals apart;
 *  - durations: average days from nomination to the exam decision, to passing the interview, to the first contract,
 *    for the decisions in the period;
 *  - the same counts by month, by nominating entity and by job position.
 * Pass rates are the regular passes over the takers. The applications counted are those the applications list's
 * filters keep (nominating entity, job position, governorate, ...).
 */
final class Dashboard
{
    /** @param array<string, mixed> $filters validated with ReportFilters (the applications list's filters and the period) */
    public static function build(array $filters): array
    {
        $period = ReportPeriod::from($filters);
        $filtered = fn () => ApplicationSearch::filter(Application::query(), ReportFilters::applicationFilters($filters));
        $applications = $filtered()->with('statusEvents')->get(['id', 'nominating_entity_id', 'job_position_id', 'created_at']);
        // The same applications as a subquery, not a list of ids: no limit on how many a report may count.
        $ids = $filtered()->select('applications.id');

        $stages = $applications->mapWithKeys(fn (Application $application) => [$application->id => ApplicationStages::of($application)]);
        $byId = $applications->keyBy('id');

        $interviews = Interview::query()
            ->whereIn('application_id', $ids)
            ->where('status', InterviewStatus::Recorded)
            ->get(['id', 'application_id', 'result', 'held_at']);

        $contracts = Contract::query()
            ->whereIn('application_id', $ids)
            ->get(['id', 'application_id', 'sequence', 'written_on']);

        // One list of dated facts, each counted where it falls: [kind, application id, moment].
        $facts = [];
        $durations = ['to_exam' => [], 'to_interview' => [], 'to_contract' => []];

        foreach ($stages as $id => $stage) {
            $nominated = $stage['nominated_at'];
            $facts[] = ['nominations', $id, $nominated];

            foreach ($stage['exams'] as $exam) {
                $facts[] = ["exam_{$exam['result']}", $id, $exam['at']];

                if (in_array($exam['result'], ['passed', 'failed'], true)) {
                    $facts[] = ['exam_takers', $id, $exam['at']];
                }

                if ($exam['exception'] !== null) {
                    $facts[] = ['exam_passed_by_exception', $id, $exam['exception']['at']];

                    if ($period->contains($exam['at'])) {
                        $facts[] = ['exam_failed_then_excepted', $id, $exam['at']];
                    }
                }

                if ($exam['excuse'] !== null && $period->contains($exam['at'])) {
                    $facts[] = ['exam_absent_excused', $id, $exam['at']];
                }

                if ($exam['round'] === 1 && $period->contains($exam['at'])) {
                    $durations['to_exam'][] = self::days($nominated, $exam['at']);
                }
            }
        }

        foreach ($interviews as $interview) {
            $result = $interview->result->value;
            $facts[] = ["interview_{$result}", $interview->application_id, $interview->held_at];

            if ($result !== 'absent') {
                $facts[] = ['interview_takers', $interview->application_id, $interview->held_at];
            } elseif (isset($stages[$interview->application_id]['interview_excuses'][$interview->id])) {
                $facts[] = ['interview_absent_excused', $interview->application_id, $interview->held_at];
            }

            if ($result === 'passed' && $period->contains($interview->held_at)) {
                $durations['to_interview'][] = self::days($stages[$interview->application_id]['nominated_at'], $interview->held_at);
            }
        }

        foreach ($contracts as $contract) {
            $kind = $contract->sequence === 1 ? 'contracts_written' : 'contract_renewals';
            $facts[] = [$kind, $contract->application_id, $contract->written_on, true];

            if ($contract->sequence === 1 && $period->containsDate($contract->written_on)) {
                $durations['to_contract'][] = self::days($stages[$contract->application_id]['nominated_at'], $contract->written_on);
            }
        }

        $inPeriod = array_values(array_filter($facts, fn (array $fact) => ($fact[3] ?? false) ? $period->containsDate($fact[2]) : $period->contains($fact[2])));
        $count = fn (string $kind, ?callable $where = null) => count(array_filter($inPeriod, fn (array $fact) => $fact[0] === $kind && ($where === null || $where($fact))));

        $earliest = collect($stages)->pluck('nominated_at')->filter()->min();
        $monthly = array_map(fn (string $month) => ['month' => $month] + self::counts(
            fn (string $kind) => $count($kind, fn (array $fact) => ReportPeriod::monthOf($fact[2]) === $month),
            ['nominations', 'exam_takers', 'interview_takers', 'contracts_written'],
        ), $period->months($earliest));

        return [
            'period' => $period->toArray(),
            'totals' => [
                'nominations' => $count('nominations'),
                'exams' => [
                    'takers' => $count('exam_takers'),
                    'passed' => $count('exam_passed'),
                    'passed_by_exception' => $count('exam_passed_by_exception'),
                    'failed' => $count('exam_failed'),
                    'failed_then_excepted' => $count('exam_failed_then_excepted'),
                    'absent' => $count('exam_absent'),
                    'absent_excused' => $count('exam_absent_excused'),
                    'pass_rate_percent' => self::rate($count('exam_passed'), $count('exam_takers')),
                ],
                'interviews' => [
                    'takers' => $count('interview_takers'),
                    'passed' => $count('interview_passed'),
                    'failed' => $count('interview_failed'),
                    'absent' => $count('interview_absent'),
                    'absent_excused' => $count('interview_absent_excused'),
                    'pass_rate_percent' => self::rate($count('interview_passed'), $count('interview_takers')),
                ],
                'contracts' => ['written' => $count('contracts_written'), 'renewals' => $count('contract_renewals')],
                'average_days' => array_map(fn (array $days) => $days === [] ? null : round(array_sum($days) / count($days), 1), $durations),
            ],
            'monthly' => $monthly,
            'by_nominating_entity' => self::breakdown($inPeriod, $byId->map->nominating_entity_id->all(), NominatingEntity::withTrashed()->pluck('name_ar', 'id')->all()),
            'by_job_position' => self::breakdown($inPeriod, $byId->map->job_position_id->all(), JobPosition::withTrashed()->pluck('name_ar', 'id')->all()),
        ];
    }

    /**
     * @param  list<array>  $facts  in the period
     * @param  array<string, int>  $groupOf  application id => list entry id
     * @param  array<int, string>  $names
     */
    private static function breakdown(array $facts, array $groupOf, array $names): array
    {
        $kinds = ['nominations', 'exam_passed', 'exam_passed_by_exception', 'interview_passed', 'contracts_written'];
        $rows = [];

        foreach ($facts as [$kind, $application]) {
            if (! in_array($kind, $kinds, true)) {
                continue;
            }

            $group = $groupOf[$application];
            $rows[$group] ??= ['id' => $group, 'name' => $names[$group] ?? (string) $group] + array_fill_keys($kinds, 0);
            $rows[$group][$kind]++;
        }

        usort($rows, fn (array $a, array $b) => [$b['nominations'], $a['name']] <=> [$a['nominations'], $b['name']]);

        return $rows;
    }

    /** @param callable(string): int $count */
    private static function counts(callable $count, array $kinds): array
    {
        return array_combine($kinds, array_map($count, $kinds));
    }

    private static function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(100 * $part / $whole, 1);
    }

    private static function days(?CarbonInterface $from, CarbonInterface $to): float
    {
        return $from === null ? 0.0 : round($from->diffInHours($to) / 24, 1);
    }
}
