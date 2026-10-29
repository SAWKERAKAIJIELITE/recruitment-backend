<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Enums\DocumentScope;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Support\DocumentFile;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use App\Support\SafeFilename;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uploading and archiving documents (D-057). A person-level document (ID card, certificates) is stored on the
 * person, whichever screen it is uploaded from; an application-level one (contract) on its application. The file
 * goes to the private disk under a generated name; the row records who uploaded what, with its SHA-256.
 */
class ManageDocuments
{
    public function upload(UploadedFile $file, DocumentType $type, Candidate $candidate, ?Application $application, ?string $notes, StaffUser $actor): Document
    {
        if ($type->trashed()) {
            throw ValidationException::withMessages(['document_type_id' => [__('documents.type_archived')]]);
        }

        if ($type->scope === DocumentScope::Application && $application === null) {
            throw ValidationException::withMessages(['document_type_id' => [__('documents.needs_application')]]);
        }

        ['mime' => $mime, 'extension' => $extension] = DocumentFile::inspect($file);

        $id = (string) Str::uuid7();
        $disk = Storage::disk(config('documents.disk'));
        $path = $disk->putFileAs(trim((string) config('documents.directory'), '/') . "/{$candidate->id}", $file, "{$id}.{$extension}");

        try {
            return DB::transaction(function () use ($id, $file, $type, $candidate, $application, $notes, $actor, $mime, $path) {
                $document = new Document();
                $document->forceFill([
                    'id' => $id,
                    'document_type_id' => $type->id,
                    'candidate_id' => $candidate->id,
                    'application_id' => $type->scope === DocumentScope::Application ? $application->id : null,
                    'original_filename' => SafeFilename::of($file->getClientOriginalName(), 'document'),
                    'stored_path' => $path,
                    'mime_type' => $mime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'notes' => $notes,
                    'uploaded_by' => $actor->id,
                    'created_at' => Date::now(),
                ])->save();

                AuditTrail::record('document.uploaded', $actor, [
                    'document_id' => $document->id,
                    'type' => $type->name_ar,
                    'candidate_id' => $candidate->id,
                    'application_id' => $document->application_id,
                ]);

                return $document;
            });
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    /** The file stays on disk and the row stays as evidence; it leaves the lists and the requirements. */
    public function archive(Document $document, string $reason, StaffUser $actor): Document
    {
        return DB::transaction(function () use ($document, $reason, $actor) {
            $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->isArchived()) {
                throw ValidationException::withMessages(['document' => [__('documents.already_archived')]]);
            }

            $document->forceFill(['archived_at' => Date::now(), 'archived_by' => $actor->id, 'archive_reason' => trim($reason)])->save();

            AuditTrail::record('document.archived', $actor, ['document_id' => $document->id, 'reason' => $document->archive_reason]);

            return $document;
        });
    }
}
