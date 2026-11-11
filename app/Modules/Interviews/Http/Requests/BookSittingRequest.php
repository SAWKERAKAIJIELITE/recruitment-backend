<?php

namespace App\Modules\Interviews\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Book several applications into a committee sitting, in the order given (for example the order of the interview
 * queue): `application_ids`, `first_at` (default: the sitting's start), `minutes_between` (default 30).
 */
class BookSittingRequest extends FormRequest
{
    public const MAX = 100;

    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'application_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX],
            'application_ids.*' => ['required', 'uuid', 'distinct', 'exists:applications,id'],
            'first_at' => ['nullable', 'date'],
            'minutes_between' => ['nullable', 'integer', 'min:0', 'max:480'],
        ];
    }
}
