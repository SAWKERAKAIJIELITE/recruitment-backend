<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentType */
class DocumentTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            /** candidate (the person's, shared by every application) or application. */
            'scope' => $this->scope->value,
            /** incoming (brought by the candidate) or outgoing (issued by the organization). */
            'direction' => $this->direction->value,
            /** Required from this application status on; null: optional. */
            'required_from_status' => $this->required_from_status?->value,
            'archived' => $this->trashed(),
        ];
    }
}
