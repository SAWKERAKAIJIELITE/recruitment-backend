<?php

namespace App\Modules\Imports\Support;

/** One cell as read from the sheet: its typed value and the text Excel would display. */
final class CellValue
{
    public function __construct(
        public readonly mixed $value,       // string, int, float, bool or null (formulas: their cached result)
        public readonly string $display,    // formatted as Excel shows it
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->value === null || (is_string($this->value) && trim($this->value) === '');
    }

    /** Excel stored a number (not text): leading zeros are gone, dates are serial numbers. */
    public function isNumeric(): bool
    {
        return is_int($this->value) || is_float($this->value);
    }

    /** The value as text, numbers without decimals or exponent when they are whole. */
    public function text(): string
    {
        if (is_float($this->value) && floor($this->value) === $this->value && abs($this->value) < 1e15) {
            return sprintf('%.0f', $this->value);
        }

        if (is_bool($this->value)) {
            return $this->value ? '1' : '0';
        }

        return trim((string) $this->value);
    }
}
