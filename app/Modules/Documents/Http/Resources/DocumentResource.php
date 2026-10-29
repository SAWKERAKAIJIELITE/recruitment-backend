<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public const RELATIONS = ['type', 'application', 'uploader', 'archiver'];

    public function toArray(Request $request): array
    {
        $person = fn($user) => $user === null ? null : ['id' => $user->id, 'username' => $user->username, 'full_name' => $user->full_name];

        return [
            'id' => $this->id,
            'type' => ['id' => $this->type->id, 'name_ar' => $this->type->name_ar, 'scope' => $this->type->scope->value, 'direction' => $this->type->direction->value],
            /** Null for a document of the person. */
            'application' => $this->application === null ? null : ['id' => $this->application->id, 'nomination_number' => $this->application->nomination_number],
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'notes' => $this->notes,
            'uploaded_by' => $person($this->uploader),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            /** Null while active. */
            'archived' => $this->archived_at === null ? null : [
                'at' => $this->archived_at->toIso8601String(),
                'by' => $person($this->archiver),
                'reason' => $this->archive_reason,
            ],
            'download_url' => url("/api/v1/staff/documents/{$this->id}/download"),
        ];
    }
}
