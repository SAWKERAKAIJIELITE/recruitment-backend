<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Identity\Models\StaffUser;
use App\Support\ArabicText;
use Illuminate\Validation\ValidationException;

/** Document types: names unique in any spelling; archived, never deleted. A type's scope never changes. */
class ManageDocumentTypes
{
    /** @param array{name_ar: string, scope: string, direction: string, required_from_status?: ?string} $data */
    public function create(array $data, StaffUser $actor): DocumentType
    {
        $this->assertNameIsFree($data['name_ar'], null);
        $type = DocumentType::create($data);
        AuditTrail::record('document_type.created', $actor, ['document_type_id' => $type->id] + $data);

        return $type;
    }

    /** @param array{name_ar?: string, direction?: string, required_from_status?: ?string} $changes */
    public function update(DocumentType $type, array $changes, StaffUser $actor): DocumentType
    {
        if (isset($changes['name_ar'])) {
            $this->assertNameIsFree($changes['name_ar'], $type->id);
        }

        $type->fill($changes)->save();
        AuditTrail::record('document_type.updated', $actor, ['document_type_id' => $type->id, 'changes' => $changes]);

        return $type;
    }

    public function archive(DocumentType $type, StaffUser $actor): DocumentType
    {
        $type->delete();
        AuditTrail::record('document_type.archived', $actor, ['document_type_id' => $type->id]);

        return $type;
    }

    public function restore(DocumentType $type, StaffUser $actor): DocumentType
    {
        $type->restore();
        AuditTrail::record('document_type.restored', $actor, ['document_type_id' => $type->id]);

        return $type;
    }

    private function assertNameIsFree(string $name, ?int $exceptId): void
    {
        $clash = DocumentType::withTrashed()
            ->where('name_key', ArabicText::searchKey($name))
            ->when($exceptId !== null, fn($query) => $query->whereKeyNot($exceptId))
            ->first(['id', 'deleted_at']);

        if ($clash !== null) {
            throw ValidationException::withMessages(['name_ar' => [__($clash->trashed() ? 'recruitment.name_archived' : 'recruitment.name_taken')]]);
        }
    }
}
