<?php

namespace App\Support;

/** Text helpers for Arabic data entry. Shared by every module. */
final class ArabicText
{
    private const LETTER_MAP = [
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ٱ' => 'ا',   // alef variants
        'ى' => 'ي',                                        // alef maqsura
        'ة' => 'ه',                                        // ta marbuta (people type both)
    ];

    private const DIGIT_MAP = [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',   // Arabic-Indic
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',   // Extended Arabic-Indic
    ];

    /**
     * The form two spellings of the same name share, for searching and duplicate-name checks:
     * lowercase, no harakat/tatweel, look-alike letters merged, whitespace collapsed.
     * Never use it for display.
     */
    public static function searchKey(string $value): string
    {
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim(mb_strtolower(strtr($value, self::LETTER_MAP)));
    }

    /** ١٢٣ and ۱۲۳ become 123 (people type Arabic-Indic digits all the time). */
    public static function digitsToAscii(string $value): string
    {
        return strtr($value, self::DIGIT_MAP);
    }
}
