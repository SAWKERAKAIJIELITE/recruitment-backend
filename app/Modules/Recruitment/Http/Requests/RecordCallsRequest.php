<?php

namespace App\Modules\Recruitment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Calls Recruitment made about the candidates' appointments (D-105). */
class RecordCallsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    public function rules(): array
    {
        return [
            /** Applications with an appointment ahead (an exam booked, or an interview scheduled). */
            'application_ids' => ['required', 'array', 'min:1', 'max:500'],
            'application_ids.*' => ['uuid', 'distinct'],
            /** true: told of the time and place; false: not reached (no answer, phone off). */
            'reached' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['application_ids' => 'الطلبات', 'application_ids.*' => 'الطلب', 'reached' => 'نتيجة الاتصال', 'note' => 'الملاحظة'];
    }
}
