<?php

namespace App\Modules\Reports\Queries;

use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The results of applications as a candidate may see them (owner: "exam and interview results, except interview notes
 * or exam failure cause, can be exported as a report to the candidates"; D-066).
 *
 * Exam: the outcome recorded in the application's history (passed, failed, absent), and whether the candidate went on
 * to the interview by an exception (a corrected outcome counts as corrected). Dates are days in the report time zone. Interview: every recorded
 * round with its result, date and the committee's three grades. Left out on purpose: the committee's notes and
 * summary, and every reason (failure causes, exception and excuse reasons).
 */
final class CandidateResults
{
    public const RELATIONS = ['candidate', 'jobPosition', 'nominatingEntity', 'statusEvents'];

    /**
     * @param  Collection<int, Application>  $applications  loaded with RELATIONS
     * @return list<array{application: Application, exam: ?array, interviews: list<array>, interview: ?array}>
     */
    public static function of(Collection $applications): array
    {
        $interviews = Interview::query()
            ->with('sitting')
            ->whereIn('application_id', $applications->map(fn (Application $application) => $application->id)->all())
            ->where('status', InterviewStatus::Recorded)
            ->orderBy('round')
            ->get()
            ->groupBy('application_id');

        return $applications->map(function (Application $application) use ($interviews) {
            $rounds = ($interviews->get($application->id) ?? collect())->map(fn (Interview $interview) => self::interview($interview))->values()->all();

            return [
                'application' => $application,
                'exam' => self::exam($application),
                'interviews' => $rounds,
                'interview' => $rounds === [] ? null : $rounds[array_key_last($rounds)],
            ];
        })->values()->all();
    }

    /**
     * The latest exam outcome in the history (as corrected, when a correction replaced it), and whether an exception
     * then admitted the candidate to the interview: read the way the staff reports read it (ApplicationStages).
     */
    private static function exam(Application $application): ?array
    {
        $exams = ApplicationStages::of($application)['exams'];

        if ($exams === []) {
            return null;
        }

        $exam = $exams[array_key_last($exams)];

        return [
            'result' => $exam['result'],
            'label' => __("reports.results.{$exam['result']}", [], 'ar'),
            'decided_on' => self::day($exam['at']),
            'admitted_by_exception' => $exam['exception'] !== null,
        ];
    }

    /** The day in the report time zone (Damascus), as the staff reports show it. */
    private static function day(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone((string) config('reports.timezone'))->toDateString();
    }

    private static function interview(Interview $interview): array
    {
        $grade = fn (?InterviewGrade $grade) => $grade === null ? null : ['value' => $grade->value, 'label' => $grade->labelAr()];

        return [
            'round' => $interview->round,
            'result' => $interview->result->value,
            'label' => $interview->result->labelAr(),
            'held_on' => self::day($interview->held_at),
            'grades' => [
                'scientific' => $grade($interview->scientific_grade),
                'general' => $grade($interview->general_grade),
                'overall' => $grade($interview->overall_grade),
            ],
        ];
    }
}
