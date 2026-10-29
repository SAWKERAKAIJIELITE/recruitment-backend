<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Upload (multipart/form-data): the type, the file (PDF, JPEG, PNG or WebP, checked by content) and optional notes. */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')],
            'file' => ['required', 'file', 'max:' . (int) config('documents.max_file_kilobytes')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
