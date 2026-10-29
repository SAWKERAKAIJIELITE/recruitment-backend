<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Actions\ManageDocumentTypes;
use App\Modules\Documents\Http\Requests\DocumentTypeRequest;
use App\Modules\Documents\Http\Resources\DocumentTypeResource;
use App\Modules\Documents\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Document types (D-057). Any staff user reads them; candidates.manage changes them. */
class DocumentTypeController extends Controller
{
    /** List document types. Archived ones only with `include_archived` = `1` or `true`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['include_archived' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);

        return DocumentTypeResource::collection(
            DocumentType::query()->when($request->boolean('include_archived'), fn($query) => $query->withTrashed())->orderBy('id')->get(),
        );
    }

    /** Add a document type. */
    public function store(DocumentTypeRequest $request, ManageDocumentTypes $types): JsonResponse
    {
        return DocumentTypeResource::make($types->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    /** Change a document type: its name, direction or the status it is required from (never its scope). */
    public function update(DocumentTypeRequest $request, int $id, ManageDocumentTypes $types): DocumentTypeResource
    {
        return new DocumentTypeResource($types->update($this->type($id), $request->validated(), $request->user()));
    }

    /** Archive a document type: no new uploads; existing documents stay. */
    public function archive(Request $request, int $id, ManageDocumentTypes $types): DocumentTypeResource
    {
        return new DocumentTypeResource($types->archive($this->type($id), $request->user()));
    }

    /** Bring an archived document type back. */
    public function restore(Request $request, int $id, ManageDocumentTypes $types): DocumentTypeResource
    {
        return new DocumentTypeResource($types->restore($this->type($id), $request->user()));
    }

    private function type(int $id): DocumentType
    {
        return DocumentType::withTrashed()->findOrFail($id);
    }
}
