<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // protected function prepareForValidation()
    // {
    //     $allowed = ['public_key', 'label', 'mac_address', 'os_info'];

    //     // Isolate only allowed fields, completely ignoring everything else passed in
    //     $this->replace($this->only($allowed));

    //     // OPTIONAL: If you prefer to abort the request rather than strip silently:
    //     /*
    //     $extraKeys = array_diff(array_keys($this->all()), $allowed);
    //     if (!empty($extraKeys)) {
    //         throw ValidationException::withMessages(['payload' => 'Payload contains unallowed properties.']);
    //     }
    //     */
    // }

    public function rules(): array
    {
        return [
            'public_key' => ['required', 'string', 'max:2000'],
            'label' => ['required', 'string', 'max:100'],
            'mac_address' => ['required', 'mac_address'],
            'os_info' => ['required', 'string', 'max:255'],
        ];
    }
}
