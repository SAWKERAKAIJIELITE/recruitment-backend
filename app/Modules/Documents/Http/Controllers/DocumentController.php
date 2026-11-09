<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Enums\DocumentScope;
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
 * Documents of people and applications (D-057), the Documenting office's (D-085): reading and downloading with
 * documents.view, uploading and archiving with documents.manage. The Contracts office reaches only the documents of
 * an application (its contract): it reads them with contracts.view and uploads or archives them with
 * contracts.manage; a person's documents (ID card, certificates) are not its own (403).
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
     * (active documents of that type) and `missing`. Missing documents never block anything. The Contracts office
     * gets the application's own documents and types only.
     */
    public function forApplication(Request $request, Application $application): JsonResponse
    {
        $request->validate(['include_archived' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);

        $applicationOnly = self::applicationDocumentsOnly($request, 'view');

        $documents = Document::query()
            ->with(DocumentResource::RELATIONS)
            ->where('candidate_id', $application->candidate_id)
            ->when(
                $applicationOnly,
                fn($query) => $query->where('application_id', $application->id),
                fn($query) => $query->where(fn($query) => $query->whereNull('application_id')->orWhere('application_id', $application->id)),
            )
            ->when(! $request->boolean('include_archived'), fn($query) => $query->active())
            ->orderByDesc('created_at')
            ->get();

        $requirements = array_filter(DocumentRequirements::of($application), fn(array $row) => ! $applicationOnly || $row['type']->scope === DocumentScope::Application);

        return response()->json(['data' => [
            'documents' => DocumentResource::collection($documents)->resolve($request),
            'requirements' => array_values(array_map(fn(array $row) => [
                'type' => DocumentTypeResource::make($row['type'])->resolve($request),
                'required_now' => $row['required_now'],
                'present' => $row['present'],
                'missing' => $row['missing'],
            ], $requirements)),
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
     * certificates) is stored on the person; an application-level one (contract) on this application. The Contracts
     * office uploads application-level types only (403).
     */
    public function storeForApplication(StoreDocumentRequest $request, Application $application, ManageDocuments $documents): JsonResponse
    {
        $type = $this->type($request);
        abort_if(self::applicationDocumentsOnly($request, 'manage') && $type->scope !== DocumentScope::Application, 403);

        $document = $documents->upload($request->file('file'), $type, $application->candidate, $application, $request->validated('notes'), $request->user());

        return DocumentResource::make($document->load(DocumentResource::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Download a document
     *
     * The original file, as an attachment. Every download is audited. The Contracts office downloads an
     * application's documents only (403).
     */
    public function download(Request $request, Document $document): StreamedResponse
    {
        abort_if(self::applicationDocumentsOnly($request, 'view') && $document->application_id === null, 403);

        AuditTrail::record('document.downloaded', $request->user(), ['document_id' => $document->id]);

        return Storage::disk(config('documents.disk'))->download($document->stored_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Archive a document: it stays as evidence, leaves the lists and no longer counts as present. The Contracts office archives an application's documents only (403). */
    public function archive(ArchiveDocumentRequest $request, Document $document, ManageDocuments $documents): DocumentResource
    {
        abort_if(self::applicationDocumentsOnly($request, 'manage') && $document->application_id === null, 403);

        return new DocumentResource($documents->archive($document, $request->validated('reason'), $request->user())->load(DocumentResource::RELATIONS));
    }

    /** Without documents.view / documents.manage, the route let the user in through contracts.view / contracts.manage. */
    private static function applicationDocumentsOnly(Request $request, string $ability): bool
    {
        return ! $request->user()->hasPermission("documents.{$ability}");
    }

    private function type(StoreDocumentRequest $request): DocumentType
    {
        return DocumentType::withTrashed()->findOrFail($request->validated('document_type_id'));
    }
}
