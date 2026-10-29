<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use App\Modules\Recruitment\Support\ApplicationRules as R;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:users.manage route middleware decides
    }

    public function rules(): array
    {
        $name = ['string', 'min:' . R::NAME_MIN, 'max:' . R::NAME_MAX, 'not_regex:' . R::NAME_FORBIDDEN];

        return [
            'personal_number' => ['required', 'string', 'max_digits:50', 'min_digits:4', 'numeric', 'unique:staff_users,personal_number'],
            'first_name' => ['required', ...$name],
            'last_name' => ['required', ...$name],
            'is_active' => ['required', 'boolean'],
            'username' => ['required', 'string', 'max:50', 'min:3', 'regex:/^[a-z._-]{3,50}$/','unique:staff_users,username'],
            'role' => ['required', 'string', 'exists:roles,slug'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                Password::min(8) // Minimum length of 8 characters
                    ->letters()          // Must contain at least one letter
                    ->mixedCase()        // Must contain both uppercase and lowercase letters
                    ->numbers()          // Must contain at least one number
                    ->symbols()          // Must contain at least one special character (!@#$%^&*)
                    ->uncompromised(),
            ],
        ];
    }
}
