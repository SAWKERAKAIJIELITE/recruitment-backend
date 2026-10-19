<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;


class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'challenge_id' => ['required', 'uuid'],
            'signature' => ['required', 'string', 'max:512'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255',
                // Password::min(8) // Minimum length of 8 characters
                //     ->letters()          // Must contain at least one letter
                //     ->mixedCase()        // Must contain both uppercase and lowercase letters
                //     ->numbers()          // Must contain at least one number
                //     ->symbols()          // Must contain at least one special character (!@#$%^&*)
                //     ->uncompromised(),
            ],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
