<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Cancelling a session's taking-out to a hall (D-102). */
class HallCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route's permission (exams.offline)
    }

    public function rules(): array
    {
        return [
            /** The download being cancelled (the session's `hall.download_id`). */
            'download_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            /** true when someone else may have seen the questions (the laptop lost or stolen): they leave the bank. */
            'questions_exposed' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['download_id' => 'معرّف الإخراج', 'reason' => 'السبب', 'questions_exposed' => 'انكشاف الأسئلة'];
    }
}
