<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** A new password set by an administrator, with the same strength rules as a new account. */
class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:users.manage route middleware decides
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()],
        ];
    }
}
