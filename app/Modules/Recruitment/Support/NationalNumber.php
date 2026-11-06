<?php

namespace App\Modules\Recruitment\Support;

use App\Support\ArabicText;

final class NationalNumber
{
    /** A national number has 11 to 20 digits (the database checks the same). */
    public const MIN_LENGTH = 11;

    public const MAX_LENGTH = 20;

    /**
     * ASCII digits, separators dropped: "٠١٢-345 6" becomes "0123456". Anything else stays, so a letter typed for a
     * digit ("O" for 0) is refused by the digits rule instead of silently giving another person's number.
     */
    public static function normalize(string $value): string
    {
        return preg_replace('/[\s\-.\x{2010}-\x{2015}_\/]+/u', '', ArabicText::digitsToAscii($value)) ?? '';
    }
}
