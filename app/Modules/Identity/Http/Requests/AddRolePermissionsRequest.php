<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:users.manage route middleware decides
    }

    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,slug'],
        ];
    }
}
