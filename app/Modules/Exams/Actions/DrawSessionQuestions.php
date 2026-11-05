<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Exams\Enums\Difficulty;
use App\Modules\Exams\Models\ExamPlanSubject;
use App\Modules\Exams\Models\ExamPlanSubjectPart;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Random\Randomizer;

/**
 * Draws a session's questions from the bank, once (D-056): for each exam item of the plan (a subject scored as a
 * whole, or an exam part), the plan's number of easy, medium and hard questions, chosen at random among the active
 * questions, each at its current version. The draw is frozen once someone starts; until then it can be drawn again.
 * The session's order is the plan's (subject, part, easy to hard); every attempt shuffles it.
 */
final class DrawSessionQuestions
{
    public function __construct(private readonly Randomizer $random = new Randomizer())
    {
    }

    /** @return int how many questions were drawn */
    public function draw(ExamSession $session): int
    {
        return DB::transaction(function () use ($session) {
            $session->questions()->delete();
            $plan = $session->plan()->with('subjects.parts.part', 'subjects.subject')->firstOrFail();
            $position = 0;
            $missing = [];

            foreach ($plan->subjects as $subject) {
                $items = $subject->scoredFromParts()
                    ? $subject->parts->reject(fn(ExamPlanSubjectPart $part) => $part->isManual())->map(fn(ExamPlanSubjectPart $part) => [$part, $part->part_id, "{$subject->subject->name_ar} / {$part->part->name_ar}"])
                    : collect([[$subject, null, $subject->subject->name_ar]]);

                foreach ($items as [$item, $partId, $name]) {
                    foreach ($item->difficultyCounts() ?? [] as $difficulty => $count) {
                        $versions = $this->pick($subject->subject_id, $partId, Difficulty::from($difficulty), $count);

                        if (count($versions) < $count) {
                            $missing[] = __('exams.bank_short', ['item' => $name, 'difficulty' => Difficulty::from($difficulty)->labelAr(), 'have' => count($versions), 'need' => $count]);

                            continue;
                        }

                        foreach ($versions as $versionId) {
                            $session->questions()->create([
                                'position' => ++$position,
                                'question_version_id' => $versionId,
                                'plan_subject_id' => $subject->id,
                                'plan_part_id' => $item instanceof ExamPlanSubjectPart ? $item->id : null,
                            ]);
                        }
                    }
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages(['plan_version_id' => $missing]);
            }

            return $position;
        });
    }

    /** @return list<int> current versions of $count active questions, at random */
    private function pick(int $subjectId, ?int $partId, Difficulty $difficulty, int $count): array
    {
        $questions = ExamQuestion::query()->forItem($subjectId, $partId)->where('difficulty', $difficulty)->pluck('id')->all();
        $chosen = count($questions) <= $count ? $questions : $this->random->pickArrayKeys(array_flip($questions), $count);

        return DB::table('exam_question_versions as v')
            ->whereIn('v.question_id', $chosen)
            ->whereRaw('v.version = (select max(w.version) from exam_question_versions w where w.question_id = v.question_id)')
            ->orderBy('v.question_id')
            ->pluck('v.id')
            ->all();
    }
}
