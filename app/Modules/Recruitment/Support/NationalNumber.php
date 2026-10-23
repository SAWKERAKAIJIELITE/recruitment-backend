<?php

namespace App\Modules\Recruitment\Support;

use App\Support\ArabicText;

final class NationalNumber
{
    public const LENGTH = 11;

    /** ASCII digits only: "٠١٢-345 6" becomes "0123456". Anything that is not a digit is dropped. */
    public static function normalize(string $value): string
    {
        return preg_replace('/\D/', '', ArabicText::digitsToAscii($value)) ?? '';
    }
}
