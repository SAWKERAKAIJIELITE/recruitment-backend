<?php

namespace App\Modules\Exams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** What a laptop brings back from a hall (D-102): the bundle's text exactly as sealed, and its seal. */
class HallUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route's permission (exams.offline)
    }

    public function rules(): array
    {
        return [
            /** The bundle (JSON) as text, byte for byte as the laptop sealed it. Its shape: docs/hall-exam-reply.md. */
            'bundle' => ['required', 'string', 'max:' . ((int) config('exams.hall_bundle_max_kilobytes') * 1024)],
            /** ECDSA P-256 / SHA-256 signature (DER, base64) of "recruitment-hall-exam-v1\n" + bundle, by the laptop that took the session out. */
            'signature' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['bundle' => 'ملف النتائج', 'signature' => 'الختم'];
    }
}
