<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Http\Requests\ArchiveDocumentRequest;
use App\Modules\Documents\Http\Requests\StoreDocumentRequest;
use App\Modules\Documents\Http\Resources\DocumentResource;
use App\Modules\Documents\Http\Resources\DocumentTypeResource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Support\DocumentRequirements;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents of people and applications (D-057). Reading and downloading: reports.view, candidates.manage or
 * contracts.manage. Uploading and archiving: candidates.manage or contracts.manage.
 */
class DocumentController extends Controller
{
    /**
     * A person's documents
     *
     * The person's own documents and those of all their applications, newest first. Archived ones only with
     * `include_archived` = `1` or `true`.
     */
    public function forCandidate(Request $request, Candidate $candidate): AnonymousResourceCollection
    {
        $request->validate(['include_archived' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);

        return DocumentResource::collection(
            Document::query()
                ->with(DocumentResource::RELATIONS)
                ->where('candidate_id', $candidate->id)
                ->when(! $request->boolean('include_archived'), fn($query) => $query->active())
                ->orderByDesc('created_at')
                ->get(),
        );
    }

    /**
     * An application's documents and what is missing
     *
     * `documents`: the person's documents and this application's, newest first. `requirements`: every active
     * document type with `required_now` (the application has reached the status it is required from), `present`
     * (active documents of that type) and `missing`. Missing documents never block anything.
     */
    public function forApplication(Request $request, Application $application): JsonResponse
    {
        $request->validate(['include_archived' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);

        $documents = Document::query()
            ->with(DocumentResource::RELATIONS)
            ->where('candidate_id', $application->candidate_id)
            ->where(fn($query) => $query->whereNull('application_id')->orWhere('application_id', $application->id))
            ->when(! $request->boolean('include_archived'), fn($query) => $query->active())
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => [
            'documents' => DocumentResource::collection($documents)->resolve($request),
            'requirements' => array_map(fn(array $row) => [
                'type' => DocumentTypeResource::make($row['type'])->resolve($request),
                'required_now' => $row['required_now'],
                'present' => $row['present'],
                'missing' => $row['missing'],
            ], DocumentRequirements::of($application)),
        ]]);
    }

    /**
     * Upload a document of the person
     *
     * multipart/form-data: `document_type_id`, `file`, optional `notes`. Only person-level types (422 otherwise:
     * upload application documents on the application).
     */
    public function storeForCandidate(StoreDocumentRequest $request, Candidate $candidate, ManageDocuments $documents): JsonResponse
    {
        $document = $documents->upload($request->file('file'), $this->type($request), $candidate, null, $request->validated('notes'), $request->user());

        return DocumentResource::make($document->load(DocumentResource::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Upload a document from an application
     *
     * multipart/form-data: `document_type_id`, `file`, optional `notes`. A person-level type (ID card,
     * certificates) is stored on the person; an application-level one (contract) on this application.
     */
    public function storeForApplication(StoreDocumentRequest $request, Application $application, ManageDocuments $documents): JsonResponse
    {
        $document = $documents->upload($request->file('file'), $this->type($request), $application->candidate, $application, $request->validated('notes'), $request->user());

        return DocumentResource::make($document->load(DocumentResource::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Download a document
     *
     * The original file, as an attachment. Every download is audited.
     */
    public function download(Request $request, Document $document): StreamedResponse
    {
        AuditTrail::record('document.downloaded', $request->user(), ['document_id' => $document->id]);

        return Storage::disk(config('documents.disk'))->download($document->stored_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Archive a document: it stays as evidence, leaves the lists and no longer counts as present. */
    public function archive(ArchiveDocumentRequest $request, Document $document, ManageDocuments $documents): DocumentResource
    {
        return new DocumentResource($documents->archive($document, $request->validated('reason'), $request->user())->load(DocumentResource::RELATIONS));
    }

    private function type(StoreDocumentRequest $request): DocumentType
    {
        return DocumentType::withTrashed()->findOrFail($request->validated('document_type_id'));
    }
}
