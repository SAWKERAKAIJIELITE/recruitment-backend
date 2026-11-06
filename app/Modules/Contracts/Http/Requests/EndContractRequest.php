<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Support\BusinessDay;
use Illuminate\Foundation\Http\FormRequest;

/** The end of the latest contract without renewal: `ended_on` (YYYY-MM-DD, default today, not in the future) and `reason`. */
class EndContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'ended_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDay::today()],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
