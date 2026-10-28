<?php

namespace App\Modules\Identity\Http\Requests;

use App\Rules\HexString;
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
            // The first 32 hex digits, as shown on the employee's screen ("XXXX XXXX ..."; spaces or dashes).
            'fingerprint' => ['required', 'string', 'max:64', new HexString(32)],
            'staff_user_id' => ['required', 'uuid', 'exists:staff_users,id'],
            // 'staff_user_ids.*' => ['uuid', 'distinct', 'exists:staff_users,id'],
        ];
    }
}
