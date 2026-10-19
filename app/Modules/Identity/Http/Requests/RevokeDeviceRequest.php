<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:devices.approve route middleware decides
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
