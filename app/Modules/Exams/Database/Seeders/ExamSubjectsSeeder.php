<?php

namespace App\Modules\Exams\Database\Seeders;

use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamSubjectPart;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/**
 * The subjects of the legacy template's exams sheet ("الاختبارات", columns I to P): five subjects out of 100,
 * and القرآن الكريم from التلاوة (60, in the exam) and التجويد (40, oral the same day, marked by hand; D-054). Every environment; matched by normalized name, archived
 * ones included, so running again adds only what is missing and never restores or overwrites staff changes.
 */
class ExamSubjectsSeeder extends Seeder
{
    /** subject => [part => [default weight, default assessment]], or [] for a subject scored as a whole */
    public const FROM_TEMPLATE = [
        'الحديث النبوي' => [],
        'السيرة النبوية' => [],
        'البناء الإيماني' => [],
        'فقه الجهاد' => [],
        'فقه العبادات' => [],
        'القرآن الكريم' => ['التلاوة' => ['60', 'exam'], 'التجويد' => ['40', 'manual']],
    ];

    public function run(): void
    {
        foreach (self::FROM_TEMPLATE as $subjectName => $parts) {
            $subject = ExamSubject::withTrashed()->firstOrCreate(['name_key' => ArabicText::searchKey($subjectName)], ['name_ar' => $subjectName]);

            foreach ($parts as $partName => [$weight, $assessment]) {
                ExamSubjectPart::withTrashed()->firstOrCreate(
                    ['subject_id' => $subject->id, 'name_key' => ArabicText::searchKey($partName)],
                    ['name_ar' => $partName, 'default_weight' => $weight, 'default_assessment' => $assessment],
                );
            }
        }
    }
}
