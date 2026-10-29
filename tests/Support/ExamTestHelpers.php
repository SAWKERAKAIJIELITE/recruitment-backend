<?php

namespace Tests\Support;

use App\Modules\Exams\Actions\ManageExamPlans;
use App\Modules\Exams\Enums\Difficulty;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamQuestionVersion;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamSubjectPart;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\JobPosition;

trait ExamTestHelpers
{
    /** Questions per difficulty that planContent() asks of every exam subject or part. */
    protected array $askedQuestions = ['easy' => 2, 'medium' => 2, 'hard' => 1];

    /** A user who prepares exams (system_admin holds exam_bank.manage). */
    protected function examManager(): StaffUser
    {
        return StaffUserFactory::new()->withRole('system_admin')->create();
    }

    protected function subject(string $name = 'الحديث النبوي'): ExamSubject
    {
        return ExamSubject::create(['name_ar' => $name]);
    }

    /** القرآن الكريم with التلاوة (60, exam) and التجويد (40, marked by hand); returns [subject, recitation, tajweed]. */
    protected function quran(): array
    {
        $quran = $this->subject('القرآن الكريم');
        $parts = [];

        foreach (['التلاوة' => ['60', 'exam'], 'التجويد' => ['40', 'manual']] as $name => [$weight, $assessment]) {
            $part = new ExamSubjectPart(['name_ar' => $name, 'default_weight' => $weight, 'default_assessment' => $assessment]);
            $part->subject_id = $quran->id;
            $part->save();
            $parts[] = $part;
        }

        return [$quran, ...$parts];
    }

    protected function position(string $name = 'موجه تربوي'): JobPosition
    {
        return JobPosition::create(['name_ar' => $name]);
    }

    /** Adds $perDifficulty active questions of every difficulty to a subject (or one of its parts). */
    protected function stockBank(ExamSubject $subject, ?ExamSubjectPart $part = null, int $perDifficulty = 3): void
    {
        $author = StaffUserFactory::new()->create();

        foreach (Difficulty::cases() as $difficulty) {
            for ($n = 1; $n <= $perDifficulty; $n++) {
                $question = new ExamQuestion(['difficulty' => $difficulty]);
                $question->forceFill(['subject_id' => $subject->id, 'part_id' => $part?->id, 'created_by' => $author->id])->save();
                ExamQuestionVersion::create([
                    'question_id' => $question->id, 'version' => 1, 'prompt' => "سؤال {$n}", 'options' => ['أ', 'ب', 'ج'],
                    'correct_option' => 0, 'created_by' => $author->id,
                ]);
            }
        }
    }

    /**
     * Plan content: the given subjects scored as a whole, plus the Quran from its parts when given (التلاوة in the
     * exam, التجويد marked by hand out of 40). Stocks the bank with enough questions unless $stock is false.
     */
    protected function planContent(array $subjects, ?array $quran = null, array $settings = [], bool $stock = true): array
    {
        $content = [];

        foreach ($subjects as $subject) {
            $content[] = ['subject_id' => $subject->id, 'questions' => $this->askedQuestions];
            $stock && $this->stockBank($subject);
        }

        if ($quran !== null) {
            [$subject, $recitation, $tajweed] = $quran;
            $content[] = ['subject_id' => $subject->id, 'parts' => [
                ['part_id' => $recitation->id, 'questions' => $this->askedQuestions],
                ['part_id' => $tajweed->id, 'max_score' => '40'],
            ]];
            $stock && $this->stockBank($subject, $recitation);
        }

        return $settings + ['duration_minutes' => 120, 'notes' => null, 'subjects' => $content];
    }

    /** A published version of the position's plan, through the real actions. */
    protected function publishedPlan(JobPosition $position, array $content, ?StaffUser $actor = null): ExamPlanVersion
    {
        $actor ??= $this->examManager();
        $plans = app(ManageExamPlans::class);
        $draft = $plans->createDraft($position, $actor);
        $plans->replaceDraft($draft, $content, $actor);

        return $plans->publish($draft, $actor);
    }
}
