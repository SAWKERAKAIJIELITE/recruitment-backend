<?php

namespace App\Modules\Recruitment\Support;

use App\Support\ArabicText;

/** Phone numbers are text: leading zeros and a leading + are kept. */
final class PhoneNumber
{
    /** "٠٩١٢ ٣٤٥-٦٧٨" becomes "0912345678"; spaces, dashes, dots and brackets are dropped. */
    public static function normalize(string $value): string
    {
        return preg_replace('/[\s\-\.\(\)]/u', '', ArabicText::digitsToAscii(trim($value))) ?? '';
    }
}
