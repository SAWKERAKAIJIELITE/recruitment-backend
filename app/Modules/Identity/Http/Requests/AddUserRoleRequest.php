<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:users.manage route middleware decides
    }

    public function rules(): array
    {
        return ['role' => ['required', 'string', 'exists:roles,slug']];
    }
}
