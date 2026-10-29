<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\Difficulty;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamQuestionVersion;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The question bank (D-055). A question's text, options and answer are versions: correcting them adds a version
 * and leaves the old one as it was (sessions that used it keep it). Its subject and part never change (write a new
 * question instead); its difficulty may be reclassified. Questions are archived, never deleted.
 */
class ManageQuestions
{
    /** @param list<string> $options */
    public function create(int $subjectId, ?int $partId, Difficulty $difficulty, string $prompt, array $options, int $correct, StaffUser $actor): ExamQuestion
    {
        return DB::transaction(function () use ($subjectId, $partId, $difficulty, $prompt, $options, $correct, $actor) {
            $question = new ExamQuestion(['difficulty' => $difficulty]);
            $question->forceFill(['subject_id' => $subjectId, 'part_id' => $partId, 'created_by' => $actor->id])->save();

            $this->addVersion($question, 1, $prompt, $options, $correct, $actor);

            AuditTrail::record('exam_question.created', $actor, ['question_id' => $question->id, 'subject_id' => $subjectId, 'part_id' => $partId]);

            return $question;
        });
    }

    /**
     * Changes what is sent: a new version when the text, options or answer differ; the difficulty in place.
     *
     * @param  array{prompt?: string, options?: list<string>, correct_option?: int, difficulty?: string}  $changes
     */
    public function revise(ExamQuestion $question, array $changes, StaffUser $actor): ExamQuestion
    {
        return DB::transaction(function () use ($question, $changes, $actor) {
            $question = ExamQuestion::withTrashed()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            $current = $question->currentVersion()->firstOrFail();

            $prompt = $changes['prompt'] ?? $current->prompt;
            $options = $changes['options'] ?? $current->options;
            $correct = $changes['correct_option'] ?? $current->correct_option;

            if ($correct >= count($options)) {
                throw ValidationException::withMessages(['correct_option' => [__('exams.correct_option_out_of_range')]]);
            }

            $newVersion = null;

            if (! $current->says($prompt, $options, $correct)) {
                $newVersion = $this->addVersion($question, $current->version + 1, $prompt, $options, $correct, $actor);
            }

            $from = $question->difficulty;

            if (isset($changes['difficulty'])) {
                $question->difficulty = Difficulty::from($changes['difficulty']);
            }

            $question->touch();

            AuditTrail::record('exam_question.revised', $actor, [
                'question_id' => $question->id,
                'version' => $newVersion?->version,
                'difficulty' => $from === $question->difficulty ? null : ['from' => $from->value, 'to' => $question->difficulty->value],
            ]);

            return $question;
        });
    }

    public function archive(ExamQuestion $question, StaffUser $actor): ExamQuestion
    {
        $question->delete();
        AuditTrail::record('exam_question.archived', $actor, ['question_id' => $question->id]);

        return $question;
    }

    public function restore(ExamQuestion $question, StaffUser $actor): ExamQuestion
    {
        $question->restore();
        AuditTrail::record('exam_question.restored', $actor, ['question_id' => $question->id]);

        return $question;
    }

    private function addVersion(ExamQuestion $question, int $version, string $prompt, array $options, int $correct, StaffUser $actor): ExamQuestionVersion
    {
        return ExamQuestionVersion::create([
            'question_id' => $question->id,
            'version' => $version,
            'prompt' => $prompt,
            'options' => array_values($options),
            'correct_option' => $correct,
            'created_by' => $actor->id,
        ]);
    }
}
