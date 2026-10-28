<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Hexadecimal text, optionally grouped with spaces or dashes as people type it ("3F2A 9C01 ..."), with an
 * exact number of hex digits when $length is given. Laravel has no general hexadecimal rule (`hex_color`
 * accepts colours only).
 */
final class HexString implements ValidationRule
{
    public function __construct(private readonly ?int $length = null)
    {
    }

    /** The hex digits without separators, uppercase. */
    public static function digits(string $value): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', $value) ?? '');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[0-9A-Fa-f]+([\s\-]?[0-9A-Fa-f]+)*$/', trim($value)) !== 1) {
            $fail('validation.hex_string')->translate();

            return;
        }

        if ($this->length !== null && strlen(self::digits($value)) !== $this->length) {
            $fail('validation.hex_string_length')->translate(['digits' => (string) $this->length]);
        }
    }
}
