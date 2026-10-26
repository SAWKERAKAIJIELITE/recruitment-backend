<?php

namespace App\Modules\Imports\Support;

use App\Modules\Imports\Enums\ImportField;
use App\Support\ArabicText;

/**
 * Finds which column holds which field, from the header texts (config/imports.php). Headers are compared
 * after normalisation; an exact alias match beats a contained one, and longer aliases beat shorter ones.
 * Each field and each column is used at most once.
 */
final class HeaderMatcher
{
    /**
     * @param  array<string, string>  $headers  column letter => header text
     * @return array{fields: array<string, string>, ignored: list<string>} field value => column letter; unused columns
     */
    public static function match(array $headers): array
    {
        $candidates = [];

        foreach ($headers as $column => $text) {
            $header = self::normalize($text);

            if ($header === '') {
                continue;
            }

            foreach (ImportField::cases() as $field) {
                foreach ($field->headers() as $alias) {
                    $alias = self::normalize($alias);

                    if ($alias === '') {
                        continue;
                    }

                    if ($header === $alias) {
                        $candidates[] = [2, mb_strlen($alias), $field->value, $column];
                    } elseif (str_contains(" {$header} ", " {$alias} ")) {
                        $candidates[] = [1, mb_strlen($alias), $field->value, $column];
                    }
                }
            }
        }

        // Best matches first: exact before contained, longer alias before shorter.
        usort($candidates, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        $fields = [];
        $usedColumns = [];

        foreach ($candidates as [, , $field, $column]) {
            if (! isset($fields[$field]) && ! isset($usedColumns[$column])) {
                $fields[$field] = $column;
                $usedColumns[$column] = true;
            }
        }

        $ignored = array_values(array_filter(
            array_keys($headers),
            fn(string $column) => ! isset($usedColumns[$column]) && self::normalize($headers[$column]) !== '',
        ));

        return ['fields' => $fields, 'ignored' => $ignored];
    }

    /** Arabic search key without punctuation: "الحالة الصحية/بالتفصيل" and "الحالة الصحيه بالتفصيل" are equal. */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? $text;

        return ArabicText::searchKey($text);
    }
}
