<?php

namespace App\Modules\Imports\Support;

use Illuminate\Validation\ValidationException;

/** Refusals of a whole file or batch, as 422 responses with an Arabic message (lang/ar/imports.php). */
final class ImportProblem
{
    public static function fail(string $field, string $code, array $params = []): never
    {
        throw ValidationException::withMessages([$field => [__("imports.{$code}", $params)]]);
    }
}
