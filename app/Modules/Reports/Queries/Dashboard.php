<?php

namespace App\Modules\Reports\Queries;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Recruitment\Models\NominatingEntity;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The indicators of the dashboard (legacy sheet "لوحة التحكم"; analysis doc §16), counted from what happened in the
 * period, each by its own date (see ReportPeriod):
 *  - nominations: applications nominated;
 *  - exams: decisions taken, on the exam's day. Takers attended (passed or failed, once the session's results are
 *    approved; those waiting for the approval apart, D-104); failures by decision among the failed and apart; passes
 *    by exception are counted apart, on the day of the exception, and the failed later admitted by one are shown
 *    among the failed; absences, with those whose excuse was accepted;
 *  - interviews: results recorded, by the day held; absences with their accepted excuses;
 *  - contracts: first contracts by their writing date, renewals apart;
 *  - durations: average days from nomination to the exam decision, to passing the interview, to the first contract,
 *    for the decisions in the period;
 *  - the same counts by month, by nominating entity and by job position, and by day, week or month (D-099).
 * Pass rates are the regular passes over the takers. The applications counted are those the applications list's
 * filters keep (nominating entity, job position, governorate, ...).
 *
 * Each office reads its own sections (D-107): nominations (with the starts, closures and, with imports.view, the
 * imports) Recruitment's, exams and interviews the Exams and Interviews office's (each half to its role), contracts
 * (with their endings and the vacancies) the Contracts office's, documents the Documenting office's; the general
 * manager (reports.view) every one. A section the reader does not read is null, in the totals and in the series' and
 * breakdowns' columns, and its facts are not counted at all.
 */
final class Dashboard
{
    /** The sections of the summary, each its office's (D-107). */
    public const SECTIONS = ['nominations', 'imports', 'exams', 'interviews', 'contracts', 'documents'];

    /**
     * @param  array<string, mixed>  $filters  validated with ReportFilters (the applications list's filters and the period)
     * @param  string  $groupBy  the series' buckets: day, week or month (D-099)
     * @param  list<string>|null  $sections  the sections the reader reads (null: every one)
     */
    public static function build(array $filters, string $groupBy = 'month', ?array $sections = null): array
    {
        $sections ??= self::SECTIONS;
        $reads = fn (string $section) => in_array($section, $sections, true);
        $period = ReportPeriod::from($filters);
        $filtered = fn () => ApplicationSearch::filter(Application::query(), ReportFilters::applicationFilters($filters));
        $applications = $filtered()->with('statusEvents')->get(['id', 'nominating_entity_id', 'job_position_id', 'created_at']);
        // The same applications as a subquery, not a list of ids: no limit on how many a report may count.
        $ids = $filtered()->select('applications.id');

        $stages = $applications->mapWithKeys(fn (Application $application) => [$application->id => ApplicationStages::of($application)]);
        $byId = $applications->keyBy('id');

        $interviews = ! $reads('interviews') ? collect() : Interview::query()
            ->whereIn('application_id', $ids)
            ->where('status', InterviewStatus::Recorded)
            ->get(['id', 'application_id', 'result', 'held_at']);

        $contracts = ! $reads('contracts') ? collect() : Contract::query()
            ->whereIn('application_id', $ids)
            ->get(['id', 'application_id', 'sequence', 'written_on', 'ended_on', 'end_reason']);

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

                if ($exam['failed_by_decision'] !== null) {
                    $facts[] = ['exam_failed_by_decision', $id, $exam['at']];
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

        // Only the facts of the sections the reader reads are counted (D-107).
        $facts = array_values(array_filter($facts, fn (array $fact) => $reads(self::sectionOf($fact[0]))));
        $inPeriod = array_values(array_filter($facts, fn (array $fact) => ($fact[3] ?? false) ? $period->containsDate($fact[2]) : $period->contains($fact[2])));
        $count = fn (string $kind, ?callable $where = null) => count(array_filter($inPeriod, fn (array $fact) => $fact[0] === $kind && ($where === null || $where($fact))));

        $earliest = collect($stages)->pluck('nominated_at')->filter()->min();
        $monthly = array_map(fn (string $month) => ['month' => $month] + self::readable(self::counts(
            fn (string $kind) => $count($kind, fn (array $fact) => ReportPeriod::monthOf($fact[2]) === $month),
            ['nominations', 'exam_takers', 'interview_takers', 'contracts_written'],
        ), $reads), $period->months($earliest));

        // The same counts by day, week or month (D-099): each fact on its business day, counted in one pass.
        $tz = (string) config('reports.timezone');
        $buckets = $period->buckets($groupBy, $earliest);
        $kinds = ['nominations', 'exam_takers', 'interview_takers', 'contracts_written'];
        $bucketOfDay = [];
        $perBucket = [];

        foreach ($buckets as $bucket) {
            $perBucket[$bucket['key']] = array_fill_keys($kinds, 0);

            for ($day = CarbonImmutable::parse($bucket['start']); $day->toDateString() <= $bucket['end']; $day = $day->addDay()) {
                $bucketOfDay[$day->toDateString()] = $bucket['key'];
            }
        }

        foreach ($inPeriod as $fact) {
            $day = ($fact[3] ?? false) ? $fact[2]->toDateString() : $fact[2]->copy()->setTimezone($tz)->toDateString();
            $key = $bucketOfDay[$day] ?? null;

            if ($key !== null && isset($perBucket[$key][$fact[0]])) {
                $perBucket[$key][$fact[0]]++;
            }
        }

        $series = array_map(fn (array $bucket) => $bucket + self::readable($perBucket[$bucket['key']], $reads), $buckets);
        $section = fn (string $name, \Closure $value) => $reads($name) ? $value() : null;
        $extras = new SectionFigures($ids, $period, $filters);

        return [
            'period' => $period->toArray(),
            /** Which sections this reader reads: the others are null (D-107). */
            'sections' => array_combine(self::SECTIONS, array_map($reads, self::SECTIONS)),
            'totals' => [
                'nominations' => $section('nominations', fn () => $count('nominations')),
                /** Recruitment's: applications started (D-087) and closed in the period. */
                'applications' => $section('nominations', fn () => [
                    'started' => self::decisions($applications, $period, DecisionType::Started),
                    'closed' => self::decisions($applications, $period, DecisionType::Closure),
                ]),
                /** With imports.view: the import batches approved in the period and the applications they brought. */
                'imports' => $section('imports', fn () => $extras->imports()),
                'exams' => $section('exams', fn () => [
                    'takers' => $count('exam_takers'),
                    'passed' => $count('exam_passed'),
                    'passed_by_exception' => $count('exam_passed_by_exception'),
                    'failed' => $count('exam_failed'),
                    /** Among the failed: passes the exams office failed by decision when it approved the results (D-104). */
                    'failed_by_decision' => $count('exam_failed_by_decision'),
                    'failed_then_excepted' => $count('exam_failed_then_excepted'),
                    'absent' => $count('exam_absent'),
                    'absent_excused' => $count('exam_absent_excused'),
                    /** Took the exam; the session's results are not approved yet (D-104): neither passed nor failed. */
                    'awaiting_approval' => $count('exam_pending'),
                    'pass_rate_percent' => self::rate($count('exam_passed'), $count('exam_takers')),
                ]),
                'interviews' => $section('interviews', fn () => [
                    'takers' => $count('interview_takers'),
                    'passed' => $count('interview_passed'),
                    'failed' => $count('interview_failed'),
                    'absent' => $count('interview_absent'),
                    'absent_excused' => $count('interview_absent_excused'),
                    'pass_rate_percent' => self::rate($count('interview_passed'), $count('interview_takers')),
                ]),
                'contracts' => $section('contracts', fn () => [
                    'written' => $count('contracts_written'),
                    'renewals' => $count('contract_renewals'),
                    /** Ended without renewal in the period (their application ended). */
                    'ended' => $contracts->filter(fn (Contract $contract) => $period->containsDate($contract->ended_on))->count(),
                ]),
                /** Each the section of its stage: to the exam (exams), to passing the interview (interviews), to the first contract (contracts). */
                'average_days' => [
                    'to_exam' => $section('exams', fn () => self::average($durations['to_exam'])),
                    'to_interview' => $section('interviews', fn () => self::average($durations['to_interview'])),
                    'to_contract' => $section('contracts', fn () => self::average($durations['to_contract'])),
                ],
            ],
            'monthly' => $monthly,
            /** The trend by `group_by` (day: the latest 92 days, week: from Saturday, the latest 104; month: the latest 60). */
            'series' => ['group_by' => $groupBy, 'points' => $series],
            'by_nominating_entity' => self::breakdown($inPeriod, $byId->map->nominating_entity_id->all(), NominatingEntity::withTrashed()->pluck('name_ar', 'id')->all(), $reads),
            'by_job_position' => self::breakdown($inPeriod, $byId->map->job_position_id->all(), JobPosition::withTrashed()->pluck('name_ar', 'id')->all(), $reads),
            /** The Contracts office's: the contracts that ended in the period, by their end reason (most first). */
            'contract_endings' => $section('contracts', fn () => $contracts
                ->filter(fn (Contract $contract) => $period->containsDate($contract->ended_on))
                ->countBy(fn (Contract $contract) => trim((string) $contract->end_reason))
                ->map(fn (int $count, $reason) => ['reason' => (string) $reason, 'count' => $count])
                ->sortBy([['count', 'desc'], ['reason', 'asc']])
                ->values()->all()),
            /** The Contracts office's: the vacancies of the plans in force in the period (today's without one; D-088). */
            'vacancies' => $section('contracts', fn () => $extras->vacancies()),
            /** The Documenting office's: documents uploaded in the period, and live applications missing a required one (D-057). */
            'documents' => $section('documents', fn () => $extras->documents()),
        ];
    }

    /** Which section a fact belongs to. */
    private static function sectionOf(string $kind): string
    {
        return match (true) {
            $kind === 'nominations' => 'nominations',
            str_starts_with($kind, 'exam_') => 'exams',
            str_starts_with($kind, 'interview_') => 'interviews',
            default => 'contracts',
        };
    }

    /** Counts of the sections the reader does not read become null. @param array<string, int> $counts */
    private static function readable(array $counts, \Closure $reads): array
    {
        return array_map(fn (string $kind) => $reads(self::sectionOf($kind)) ? $counts[$kind] : null, array_combine(array_keys($counts), array_keys($counts)));
    }

    /** Decisions of a type in the period (the history is loaded). */
    private static function decisions(Collection $applications, ReportPeriod $period, DecisionType $type): int
    {
        return $applications->sum(fn (Application $application) => $application->statusEvents->filter(fn ($event) => $event->decision_type === $type && $period->contains($event->occurred_at))->count());
    }

    private static function average(array $days): ?float
    {
        return $days === [] ? null : round(array_sum($days) / count($days), 1);
    }

    /**
     * @param  list<array>  $facts  in the period
     * @param  array<string, int>  $groupOf  application id => list entry id
     * @param  array<int, string>  $names
     */
    private static function breakdown(array $facts, array $groupOf, array $names, \Closure $reads): array
    {
        $kinds = ['nominations', 'exam_passed', 'exam_passed_by_exception', 'interview_passed', 'contracts_written'];
        $readable = array_values(array_filter($kinds, fn (string $kind) => $reads(self::sectionOf($kind))));
        $rows = [];

        foreach ($facts as [$kind, $application]) {
            if (! in_array($kind, $kinds, true)) {
                continue;
            }

            $group = $groupOf[$application];
            $rows[$group] ??= ['id' => $group, 'name' => $names[$group] ?? (string) $group] + array_fill_keys($kinds, 0);
            $rows[$group][$kind]++;
        }

        // Most first by the first column the reader reads; the others are null (D-107).
        $first = $readable[0] ?? 'nominations';
        usort($rows, fn (array $a, array $b) => [$b[$first], $a['name']] <=> [$a[$first], $b['name']]);

        return array_map(fn (array $row) => array_replace($row, array_fill_keys(array_diff($kinds, $readable), null)), $rows);
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
