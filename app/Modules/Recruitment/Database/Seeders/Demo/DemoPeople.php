<?php

namespace App\Modules\Recruitment\Database\Seeders\Demo;

/**
 * Invented people for the local demo data: the same person for the same index on every run, and national
 * numbers that start with "09", so demo people are easy to recognize. No real person's data (analysis doc §24).
 */
final class DemoPeople
{
    private const FIRST = ['أحمد', 'محمد', 'خالد', 'عمر', 'يوسف', 'إبراهيم', 'حسن', 'سامر', 'ماهر', 'بلال', 'عبد الله', 'مصطفى', 'سارة', 'مريم', 'هبة', 'رنا', 'آمنة', 'نور', 'ليلى', 'زينب'];

    private const FATHER = ['محمود', 'علي', 'عبد الرحمن', 'سليمان', 'حسين', 'فواز', 'نبيل', 'رياض', 'جمال', 'وليد', 'عدنان', 'منير', 'بسام'];

    private const FAMILY = ['الأحمد', 'الخطيب', 'الحلبي', 'الشامي', 'العلي', 'الحمصي', 'الزعبي', 'المصري', 'الحسن', 'النجار', 'الصباغ', 'العمر', 'القاسم', 'الجندي', 'السيد', 'الدرويش', 'الشيخ'];

    private const MOTHER = ['فاطمة', 'خديجة', 'عائشة', 'سعاد', 'هدى', 'منى', 'رغداء', 'سلوى', 'نجاح', 'وفاء', 'ابتسام'];

    /** National numbers of demo people: 09 followed by nine digits. */
    public static function nationalNumber(int $index): string
    {
        return sprintf('09%09d', $index);
    }

    /** @return array{national_number: string, full_name: string, mother_name: string, birth_date: string} */
    public static function person(int $index): array
    {
        return [
            'national_number' => self::nationalNumber($index),
            'full_name' => self::pick(self::FIRST, $index, 7) . ' ' . self::pick(self::FATHER, $index, 5) . ' ' . self::pick(self::FAMILY, $index, 3),
            'mother_name' => self::pick(self::MOTHER, $index, 4) . ' ' . self::pick(self::FAMILY, $index, 11),
            'birth_date' => sprintf('%04d-%02d-%02d', 1980 + ($index * 7) % 25, 1 + ($index * 5) % 12, 1 + ($index * 11) % 28),
        ];
    }

    public static function phone(int $index): string
    {
        return sprintf('09%d%07d', 3 + $index % 7, 1000000 + $index * 7919 % 9000000);
    }

    /** Spreads the indexes over a list without the obvious repetition of $index % count. */
    public static function pick(array $values, int $index, int $step = 1): mixed
    {
        return $values[($index * $step + intdiv($index, count($values))) % count($values)];
    }
}
