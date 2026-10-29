<?php

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Enums\DocumentDirection;
use App\Modules\Documents\Enums\DocumentScope;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new document type, or changes to one (send only what changes; the scope never changes). `required_from_status`
 * is the application status from which the document is required (`awaiting_exam`: from the nomination on); null
 * means optional.
 */
class DocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the can:candidates.manage route middleware decides
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name_ar' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'scope' => [$creating ? 'required' : 'prohibited', Rule::enum(DocumentScope::class)],
            'direction' => [$creating ? 'required' : 'sometimes', Rule::enum(DocumentDirection::class)],
            'required_from_status' => ['nullable', Rule::enum(ApplicationStatus::class)],
        ];
    }
}
