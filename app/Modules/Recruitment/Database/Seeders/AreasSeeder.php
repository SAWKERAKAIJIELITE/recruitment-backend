<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\Area;
use App\Modules\Recruitment\Models\District;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/**
 * A suggested starting list of areas (the template has none: its residence place is free text, D-043).
 * For each governorate, its main administrative areas; for دمشق, which has none, its main neighborhoods.
 * A governorate missing from the districts list (renamed or never seeded) is skipped. Staff complete the
 * list through the API.
 */
class AreasSeeder extends Seeder
{
    use SeedsReferenceList;

    public const SUGGESTED = [
        'دمشق' => ['المزة', 'كفرسوسة', 'الميدان', 'المهاجرين', 'ركن الدين', 'الشاغور', 'باب توما', 'برزة', 'القابون', 'المالكي'],
        'ريف دمشق' => ['دوما', 'التل', 'القطيفة', 'النبك', 'يبرود', 'الزبداني', 'قطنا', 'داريا', 'قدسيا'],
        'حلب' => ['جبل سمعان', 'الباب', 'منبج', 'عين العرب', 'عفرين', 'إعزاز', 'جرابلس', 'دير حافر', 'السفيرة', 'الأتارب'],
        'حمص' => ['حمص', 'المخرم', 'الرستن', 'تلكلخ', 'القصير'],
        'حماة' => ['حماة', 'السلمية', 'مصياف', 'محردة', 'السقيلبية'],
        'اللاذقية' => ['اللاذقية', 'جبلة', 'القرداحة', 'الحفة'],
        'طرطوس' => ['طرطوس', 'بانياس', 'صافيتا', 'الدريكيش', 'الشيخ بدر'],
        'إدلب' => ['إدلب', 'أريحا', 'جسر الشغور', 'معرة النعمان', 'حارم'],
        'دير الزور' => ['دير الزور', 'الميادين', 'البوكمال'],
        'الرقة' => ['الرقة', 'تل أبيض', 'الثورة'],
        'الحسكة' => ['الحسكة', 'القامشلي', 'رأس العين', 'المالكية'],
        'درعا' => ['درعا', 'إزرع', 'الصنمين'],
        'السويداء' => ['السويداء', 'شهبا', 'صلخد'],
        'القنيطرة' => ['القنيطرة', 'فيق'],
    ];

    public function run(): void
    {
        foreach (self::SUGGESTED as $districtName => $areas) {
            $district = District::withTrashed()->where('name_key', ArabicText::searchKey($districtName))->first();

            if ($district !== null) {
                $this->seedList(Area::class, $areas, ['district_id' => $district->id]);
            }
        }
    }
}
