<?php

namespace App\Modules\Interviews\Http\Requests;

use App\Modules\Interviews\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Book several applications into a committee sitting, in the order given (for example the order of the interview
 * queue): `application_ids`, `first_at` (default: the sitting's start, or 30 minutes after its latest interview),
 * `minutes_between` (5 to 480, default 30: two interviews of a sitting are 5 minutes apart at least, D-096).
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
            'minutes_between' => ['nullable', 'integer', 'min:' . Interview::MIN_MINUTES_APART, 'max:480'],
        ];
    }
}
