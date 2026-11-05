<?php

namespace App\Modules\Reports\Tables;

use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Reports\Queries\ApplicationStages;
use App\Modules\Reports\Support\ReportPeriod;
use App\Modules\Reports\Support\ReportTable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The staff's report tables, after the sheets of the legacy workbook (D-068):
 *  - بيانات المرشحين: one row per application, every field of the template, with its nomination number and status;
 *  - الاختبارات and its three views (passed, failed, absent): one row per exam decision in the period, with what
 *    followed it (an exception, an accepted excuse). The subject marks of the legacy sheet join once the exam step
 *    records attempts (D-056);
 *  - المقابلات and its three views: one row per interview in the period, with its sitting, grades, result and the
 *    committee's note (these are staff reports: the candidates' statement leaves the notes out, D-066).
 * Internal reasons (exception, excuse) are shown: these reports are for the staff.
 */
final class RecruitmentTables
{
    /** What the tables read from an application. */
    public const RELATIONS = [
        'candidate.birthPlace', 'nominatingEntity', 'jobPosition', 'category', 'academicQualification', 'specialization',
        'maritalStatus', 'healthCondition', 'residenceDistrict', 'residenceArea', 'statusEvents',
    ];

    public const EXAM_VIEWS = ['all', 'passed', 'failed', 'absent'];

    public const INTERVIEW_VIEWS = ['all', 'passed', 'failed', 'absent'];

    /** @param Collection<int, Application> $applications loaded with RELATIONS */
    public static function applications(Collection $applications, ?ReportPeriod $period = null): ReportTable
    {
        $rows = [];

        foreach ($applications as $application) {
            $nominated = ApplicationStages::of($application)['nominated_at'];

            if ($period !== null && ! $period->contains($nominated)) {
                continue;
            }

            $candidate = $application->candidate;
            $rows[] = [
                $application->nomination_number,
                $application->nominatingEntity->name_ar,
                $application->jobPosition->name_ar,
                $application->category->name_ar,
                $application->existing_employee_number,
                $candidate->full_name,
                $candidate->mother_name,
                $candidate->birthPlace->name_ar,
                $candidate->birth_date->toDateString(),
                $candidate->national_number,
                $application->maritalStatus->name_ar,
                self::join($application->healthCondition->name_ar, $application->health_details),
                $application->residenceDistrict->name_ar,
                self::join($application->residenceArea?->name_ar, $application->residence_details),
                $application->academicQualification->name_ar,
                $application->specialization->name_ar,
                self::studyingYears($application),
                $application->quran_parts_memorized,
                self::yesNo($application->works_for_other_government_entity),
                self::yesNo($application->overnight_work),
                self::yesNo($application->full_commitment),
                $application->phone_number,
                $application->notes,
                self::day($nominated),
                $application->current_status->labelAr(),
            ];
        }

        return new ReportTable(__('reports.tables.applications', [], 'ar'), self::columns([
            ['nomination_number', 14], ['nominating_entity', 18], ['job_position', 16], ['category', 12], ['employee_number', 12],
            ['full_name', 24], ['mother_name', 14], ['birth_place', 12], ['birth_date', 12], ['national_number', 15],
            ['marital_status', 12], ['health', 16], ['residence_district', 13], ['residence', 18], ['qualification', 14],
            ['specialization', 14], ['studying_years', 11], ['quran_parts', 11], ['other_government_entity', 12],
            ['overnight_work', 12], ['full_commitment', 11], ['phone_number', 13], ['notes', 22], ['nominated_on', 12], ['status', 22],
        ]), $rows, [__('reports.total_applications', [], 'ar') => count($rows)], wide: true);
    }

    /**
     * @param  Collection<int, Application>  $applications  loaded with RELATIONS
     * @param  string  $view  all, passed (regular passes and failures later admitted by an exception), failed, absent
     */
    public static function exams(Collection $applications, ReportPeriod $period, string $view = 'all'): ReportTable
    {
        $decisions = [];

        foreach ($applications as $application) {
            foreach (ApplicationStages::of($application)['exams'] as $exam) {
                $keep = $period->contains($exam['at']) && match ($view) {
                    'passed' => $exam['result'] === 'passed' || $exam['exception'] !== null,
                    'failed', 'absent' => $exam['result'] === $view,
                    default => true,
                };

                if ($keep) {
                    $decisions[] = [$application, $exam];
                }
            }
        }

        usort($decisions, fn(array $a, array $b) => [$a[1]['at'], $a[0]->nomination_number] <=> [$b[1]['at'], $b[0]->nomination_number]);

        $columns = [['nomination_number', 14], ['full_name', 24], ['nominating_entity', 18], ['qualification', 14], ['residence', 16], ['exam_round', 8], ['attendance', 9], ['exam_date', 12], ['exam_result', 11]];
        $columns = match ($view) {
            'passed' => [...$columns, ['pass_kind', 22], ['exception_reason', 26]],
            'failed' => [...$columns, ['exception', 18], ['exception_reason', 26]],
            'absent' => [...$columns, ['excuse', 18], ['excuse_reason', 26]],
            default => [...$columns, ['later_decision', 24], ['decision_reason', 26]],
        };

        $rows = array_map(function (array $decision) use ($view) {
            [$application, $exam] = $decision;
            $later = $exam['exception'] ?? $exam['excuse'];
            $row = [
                $application->nomination_number,
                $application->candidate->full_name,
                $application->nominatingEntity->name_ar,
                $application->academicQualification->name_ar,
                self::join($application->residenceDistrict->name_ar, $application->residenceArea?->name_ar),
                $exam['round'],
                __('reports.attendance.' . ($exam['result'] === 'absent' ? 'absent' : 'present'), [], 'ar'),
                self::day($exam['at']),
                __("reports.results.{$exam['result']}", [], 'ar') . ($exam['corrected'] ? ' ' . __('reports.corrected', [], 'ar') : ''),
            ];

            return [...$row, ...match ($view) {
                'passed' => [
                    $exam['exception'] === null ? __('reports.pass_kinds.regular', [], 'ar') : __('reports.pass_kinds.exception', ['date' => self::day($exam['exception']['at'])], 'ar'),
                    $exam['exception']['reason'] ?? null,
                ],
                'failed' => [$exam['exception'] === null ? null : __('reports.excepted_on', ['date' => self::day($exam['exception']['at'])], 'ar'), $exam['exception']['reason'] ?? null],
                'absent' => [$exam['excuse'] === null ? null : __('reports.excused_on', ['date' => self::day($exam['excuse']['at'])], 'ar'), $exam['excuse']['reason'] ?? null],
                default => [
                    match (true) {
                        $exam['exception'] !== null => __('reports.excepted_on', ['date' => self::day($exam['exception']['at'])], 'ar'),
                        $exam['excuse'] !== null => __('reports.excused_on', ['date' => self::day($exam['excuse']['at'])], 'ar'),
                        default => null,
                    },
                    $later['reason'] ?? null,
                ],
            }];
        }, $decisions);

        $results = array_count_values(array_map(fn(array $decision) => $decision[1]['result'], $decisions));
        $totals = [__('reports.total_decisions', [], 'ar') => count($rows)];

        foreach (['passed', 'failed', 'absent'] as $result) {
            $totals[__('reports.total_exam', ['result' => __("reports.results.{$result}", [], 'ar')], 'ar')] = $results[$result] ?? 0;
        }

        $totals[__('reports.total_excepted', [], 'ar')] = count(array_filter($decisions, fn(array $decision) => $decision[1]['exception'] !== null));
        $totals[__('reports.total_excused', [], 'ar')] = count(array_filter($decisions, fn(array $decision) => $decision[1]['excuse'] !== null));

        return new ReportTable(__("reports.tables.exams_{$view}", [], 'ar'), self::columns($columns), $rows, $totals);
    }

    /**
     * @param  Collection<int, Interview>  $interviews  with sitting.members and application (RELATIONS)
     * @param  string  $view  all (every interview dated in the period, cancelled ones too), or one recorded result
     */
    public static function interviews(Collection $interviews, ReportPeriod $period, string $view = 'all'): ReportTable
    {
        $stages = [];
        $kept = $interviews->filter(function (Interview $interview) use ($period, $view) {
            if ($view !== 'all') {
                return $interview->status === InterviewStatus::Recorded && $interview->result->value === $view && $period->contains($interview->held_at);
            }

            return $period->contains($interview->held_at ?? $interview->scheduled_at);
        })->sortBy(fn(Interview $interview) => [($interview->held_at ?? $interview->scheduled_at)->getTimestamp(), $interview->application->nomination_number])->values();

        $grade = fn(?InterviewGrade $grade) => $grade?->labelAr();
        $rows = $kept->map(function (Interview $interview) use (&$stages, $grade, $view) {
            $application = $interview->application;
            $stage = $stages[$application->id] ??= ApplicationStages::of($application);
            $exam = $stage['exams'] === [] ? null : $stage['exams'][array_key_last($stage['exams'])];
            $excuse = $stage['interview_excuses'][$interview->id] ?? null;
            $sitting = $interview->sitting;

            $row = [
                $application->nomination_number,
                $application->candidate->full_name,
                $application->nominatingEntity->name_ar,
                $exam === null ? null : self::day($exam['at']),
                $exam === null ? null : __("reports.results.{$exam['result']}", [], 'ar') . ($exam['exception'] === null ? '' : ' ' . __('reports.then_excepted', [], 'ar')),
                $interview->round,
                $sitting->committee_name ?? __('reports.one_off_sitting', [], 'ar'),
                $sitting->members->map(fn($member) => "{$member->name} ({$member->capacity->labelAr()})")->implode('، '),
                $sitting->location,
                self::time($interview->scheduled_at),
            ];

            if ($view === 'all') {
                $row[] = $interview->status->labelAr();
            }

            return [
                ...$row,
                self::day($interview->held_at),
                $interview->result === null ? null : __('reports.attendance.' . ($interview->result->value === 'absent' ? 'absent' : 'present'), [], 'ar'),
                $grade($interview->scientific_grade),
                $grade($interview->general_grade),
                $interview->result?->labelAr(),
                $grade($interview->overall_grade),
                $interview->notes,
                $excuse === null ? null : __('reports.excused_on', ['date' => self::day($excuse['at'])], 'ar'),
            ];
        })->all();

        $columns = [
            ['nomination_number', 14], ['full_name', 24], ['nominating_entity', 18], ['exam_date', 12], ['exam_result', 14],
            ['interview_round', 8], ['committee', 18], ['members', 30], ['location', 18], ['appointment', 16],
        ];

        if ($view === 'all') {
            $columns[] = ['interview_status', 12];
        }

        $columns = [...$columns, ['held_on', 12], ['attendance', 9], ['scientific_grade', 11], ['general_grade', 11], ['interview_result', 11], ['overall_grade', 11], [$view === 'absent' ? 'absence_reason' : 'committee_note', 26], ['excuse', 18]];

        $results = $kept->filter(fn(Interview $interview) => $interview->result !== null)->countBy(fn(Interview $interview) => $interview->result->value);
        $totals = [__('reports.total_interviews', [], 'ar') => count($rows)];

        foreach (['passed', 'failed', 'absent'] as $result) {
            $totals[__('reports.total_interview', ['result' => __("reports.results.{$result}", [], 'ar')], 'ar')] = $results[$result] ?? 0;
        }

        if ($view === 'all') {
            $totals[__('reports.total_waiting', [], 'ar')] = $kept->filter(fn(Interview $interview) => $interview->status === InterviewStatus::Scheduled)->count();
        }

        return new ReportTable(__("reports.tables.interviews_{$view}", [], 'ar'), self::columns($columns), $rows, $totals, wide: true);
    }

    /** @return list<ReportTable> the dashboard (Dashboard::build) as tables */
    public static function dashboard(array $summary): array
    {
        $t = fn(string $key, array $replace = []) => __("reports.dashboard.{$key}", $replace, 'ar');
        $totals = $summary['totals'];
        $percent = fn(?float $rate) => $rate === null ? null : $rate;

        $indicators = new ReportTable($t('indicators'), [[$t('indicator'), 34], [$t('value'), 14]], [
            [$t('nominations'), $totals['nominations']],
            [$t('exam_takers'), $totals['exams']['takers']],
            [$t('exam_passed'), $totals['exams']['passed']],
            [$t('exam_passed_by_exception'), $totals['exams']['passed_by_exception']],
            [$t('exam_failed'), $totals['exams']['failed']],
            [$t('exam_failed_then_excepted'), $totals['exams']['failed_then_excepted']],
            [$t('exam_absent'), $totals['exams']['absent']],
            [$t('exam_absent_excused'), $totals['exams']['absent_excused']],
            [$t('exam_pass_rate'), $percent($totals['exams']['pass_rate_percent'])],
            [$t('interview_takers'), $totals['interviews']['takers']],
            [$t('interview_passed'), $totals['interviews']['passed']],
            [$t('interview_failed'), $totals['interviews']['failed']],
            [$t('interview_absent'), $totals['interviews']['absent']],
            [$t('interview_absent_excused'), $totals['interviews']['absent_excused']],
            [$t('interview_pass_rate'), $percent($totals['interviews']['pass_rate_percent'])],
            [$t('contracts_written'), $totals['contracts']['written']],
            [$t('contract_renewals'), $totals['contracts']['renewals']],
            [$t('days_to_exam'), $totals['average_days']['to_exam']],
            [$t('days_to_interview'), $totals['average_days']['to_interview']],
            [$t('days_to_contract'), $totals['average_days']['to_contract']],
        ]);

        $monthly = new ReportTable($t('monthly'), [[$t('month'), 34], [$t('nominations'), 14], [$t('exam_takers'), 14], [$t('interview_takers'), 14], [$t('contracts_written'), 14]],
            array_map(fn(array $month) => [$month['month'], $month['nominations'], $month['exam_takers'], $month['interview_takers'], $month['contracts_written']], $summary['monthly']));

        $breakdown = fn(string $title, string $first, array $rows) => new ReportTable($title, [[$first, 34], [$t('nominations'), 14], [$t('exam_passed'), 14], [$t('exam_passed_by_exception'), 14], [$t('interview_passed'), 14], [$t('contracts_written'), 14]],
            array_map(fn(array $row) => [$row['name'], $row['nominations'], $row['exam_passed'], $row['exam_passed_by_exception'], $row['interview_passed'], $row['contracts_written']], $rows));

        return [
            $indicators,
            $monthly,
            $breakdown($t('by_nominating_entity'), __('reports.columns.nominating_entity', [], 'ar'), $summary['by_nominating_entity']),
            $breakdown($t('by_job_position'), __('reports.columns.job_position', [], 'ar'), $summary['by_job_position']),
        ];
    }

    /** @param list<array{0: string, 1: int}> $columns key => width */
    private static function columns(array $columns): array
    {
        return array_map(fn(array $column) => [__("reports.columns.{$column[0]}", [], 'ar'), $column[1]], $columns);
    }

    private static function studyingYears(Application $application): ?string
    {
        if ($application->studying_years === null) {
            return null;
        }

        return $application->studies_discontinued ? __('reports.discontinued', ['years' => $application->studying_years], 'ar') : (string) $application->studying_years;
    }

    private static function yesNo(?bool $value): ?string
    {
        return $value === null ? null : __($value ? 'reports.yes' : 'reports.no', [], 'ar');
    }

    private static function join(?string ...$parts): ?string
    {
        $parts = array_filter(array_map(fn(?string $part) => $part === null ? null : trim($part), $parts));

        return $parts === [] ? null : implode(' — ', $parts);
    }

    private static function day(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone((string) config('reports.timezone'))->toDateString();
    }

    private static function time(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone((string) config('reports.timezone'))->format('Y-m-d H:i');
    }
}
