<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:devices.approve route middleware decides
    }

    public function rules(): array
    {
        return [
            'fingerprint' => ['required', 'string', 'max:64'],
            'staff_user_ids' => ['required', 'array', 'min:1'],
            'staff_user_ids.*' => ['uuid', 'distinct', 'exists:staff_users,id'],
        ];
    }
}
