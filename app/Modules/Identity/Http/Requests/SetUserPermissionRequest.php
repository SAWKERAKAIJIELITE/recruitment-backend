<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetUserPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:users.manage route middleware decides
    }

    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', 'exists:permissions,slug'],
            'effect' => ['required', 'in:grant,deny'],
        ];
    }
}
