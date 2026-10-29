<?php

namespace App\Modules\Exams\Database\Seeders;

use App\Modules\Exams\Actions\ManageExamPlans;
use App\Modules\Exams\Actions\ManageQuestions;
use App\Modules\Exams\Enums\Assessment;
use App\Modules\Exams\Enums\Difficulty;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\JobPosition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * LOCAL DEVELOPMENT ONLY. A question bank of placeholder questions and exam plans in every state, through the real
 * actions:
 * - questions: for every subject scored in the exam and every exam part, QUESTIONS_PER_DIFFICULTY per difficulty
 *   (none for parts marked by hand);
 * - the first job position: version 1 published (120 minutes; per subject 8 easy, 8 medium, 4 hard);
 * - the second: version 1 retired, version 2 published (90 minutes, fewer questions);
 * - the third: version 1, an incomplete draft.
 *
 * Needs ReferenceDataSeeder, ExamSubjectsSeeder and DevUsersSeeder ("admin" holds exam_bank.manage). Runs once.
 */
class ExamsDemoSeeder extends Seeder
{
    public const QUESTIONS_PER_DIFFICULTY = 12;

    public function __construct(private readonly ManageExamPlans $plans, private readonly ManageQuestions $questions)
    {
    }

    public function run(): void
    {
        if (ExamPlanVersion::query()->exists() || ExamQuestion::withTrashed()->exists()) {
            $this->command?->info('Exam plans or questions already exist: demo skipped.');

            return;
        }

        $admin = StaffUser::query()->where('username', 'admin')->firstOrFail();
        $positions = JobPosition::query()->orderBy('id')->limit(3)->get();
        $now = CarbonImmutable::now();

        try {
            Date::setTestNow($now->subDays(70));
            $this->fillBank($admin);

            Date::setTestNow($now->subDays(60));
            $first = $this->plans->createDraft($positions[0], $admin);
            $this->plans->replaceDraft($first, ['duration_minutes' => 120, 'notes' => null, 'subjects' => $this->subjects(['easy' => 8, 'medium' => 8, 'hard' => 4])], $admin);
            $this->plans->publish($first, $admin);

            $second = $this->plans->createDraft($positions[1], $admin);
            $this->plans->replaceDraft($second, ['duration_minutes' => 120, 'notes' => null, 'subjects' => $this->subjects(['easy' => 8, 'medium' => 8, 'hard' => 4])], $admin);
            $this->plans->publish($second, $admin);

            Date::setTestNow($now->subDays(10));
            $next = $this->plans->createDraft($positions[1], $admin);   // a copy of version 1
            $this->plans->replaceDraft($next, ['duration_minutes' => 90, 'notes' => 'أسئلة أقل', 'subjects' => $this->subjects(['easy' => 6, 'medium' => 6, 'hard' => 3])], $admin);
            $this->plans->publish($next, $admin);

            Date::setTestNow($now->subDays(1));
            $draft = $this->plans->createDraft($positions[2], $admin);
            $this->plans->replaceDraft($draft, ['duration_minutes' => null, 'notes' => 'قيد الإعداد', 'subjects' => array_slice($this->subjects(null), 0, 2)], $admin);
        } finally {
            Date::setTestNow();
        }

        $this->command?->info('Exam demo: ' . ExamQuestion::query()->count() . ' questions, 4 plan versions (published, retired, published, draft).');
    }

    private function fillBank(StaffUser $admin): void
    {
        foreach (ExamSubject::query()->with('parts')->orderBy('id')->get() as $subject) {
            $items = $subject->parts->isEmpty()
                ? [[null, $subject->name_ar]]
                : $subject->parts->reject(fn($part) => $part->default_assessment === Assessment::Manual)->map(fn($part) => [$part->id, "{$subject->name_ar} / {$part->name_ar}"])->all();

            foreach ($items as [$partId, $name]) {
                foreach (Difficulty::cases() as $difficulty) {
                    for ($n = 1; $n <= self::QUESTIONS_PER_DIFFICULTY; $n++) {
                        $this->questions->create(
                            $subject->id,
                            $partId,
                            $difficulty,
                            "سؤال تجريبي {$n} ({$difficulty->labelAr()}) في {$name}",
                            ["الخيار الأول", "الخيار الثاني", "الخيار الثالث", "الخيار الرابع"],
                            ($n + strlen($difficulty->value)) % 4,
                            $admin,
                        );
                    }
                }
            }
        }
    }

    /** Every active subject; parts take their default weight and assessment. */
    private function subjects(?array $questions): array
    {
        return ExamSubject::query()->with('parts')->orderBy('id')->get()->map(fn(ExamSubject $subject) => [
            'subject_id' => $subject->id,
            'questions' => $subject->parts->isEmpty() ? $questions : null,
            'parts' => $subject->parts->map(fn($part) => $part->default_assessment === Assessment::Manual
                ? ['part_id' => $part->id, 'max_score' => (string) $part->default_weight]   // التجويد out of 40, as in the template
                : ['part_id' => $part->id, 'questions' => $questions])->all(),
        ])->all();
    }
}
