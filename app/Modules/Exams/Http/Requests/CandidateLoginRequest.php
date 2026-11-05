<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A candidate's login for an exam: their national number and their session's password. */
class CandidateLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'national_number' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:64'],
        ];
    }
}
