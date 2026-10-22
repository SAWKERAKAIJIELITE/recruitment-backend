<?php

namespace App\Modules\Recruitment\Support;

use App\Support\ArabicText;

final class NationalId
{
    /** Digits only, ASCII: "٠١٢-345 6" becomes "0123456". Leading zeros are kept (it is text, not a number). */
    public static function normalize(string $value): string
    {
        return preg_replace('/\D/', '', ArabicText::digitsToAscii($value)) ?? '';
    }

    /** Hides all but the last three digits. */
    public static function mask(string $normalized): string
    {
        return str_repeat('*', max(strlen($normalized) - 3, 0)) . substr($normalized, -3);
    }
}
