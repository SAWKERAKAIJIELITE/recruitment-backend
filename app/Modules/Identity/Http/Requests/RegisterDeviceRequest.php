<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'public_key' => ['required', 'string', 'max:2000'],
            'label' => ['required', 'string', 'max:100'],
            'mac_address' => ['nullable', 'string', 'max:64'],
            'os_info' => ['nullable', 'string', 'max:255'],
        ];
    }
}
