<?php

namespace App\Modules\Recruitment\Support;

use App\Support\ArabicText;

final class NationalNumber
{
    /** A national number has 11 to 20 digits (the database checks the same). */
    public const MIN_LENGTH = 11;

    public const MAX_LENGTH = 20;

    /** ASCII digits only: "٠١٢-345 6" becomes "0123456". Anything that is not a digit is dropped. */
    public static function normalize(string $value): string
    {
        return preg_replace('/\D/', '', ArabicText::digitsToAscii($value)) ?? '';
    }
}
