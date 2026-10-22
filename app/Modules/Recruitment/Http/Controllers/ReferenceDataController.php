<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recruitment\Actions\ManageReferenceData;
use App\Modules\Recruitment\Enums\ReferenceType;
use App\Modules\Recruitment\Http\Requests\ReferenceItemRequest;
use App\Modules\Recruitment\Http\Resources\ReferenceItemResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The managed lists. {type} is one of: source-offices, job-titles, governorates, categories.
 * Any signed-in staff user can read them; changing them needs candidates.manage.
 */
class ReferenceDataController extends Controller
{
    /** List entries. Archived ones are hidden unless include_archived=1. */
    public function index(Request $request, string $type): AnonymousResourceCollection
    {
        $request->validate(['include_archived' => ['nullable', 'boolean']]);

        $query = ReferenceType::from($type)->model()::query()->orderBy('name_key');

        if ($request->boolean('include_archived')) {
            $query->withTrashed();
        }

        return ReferenceItemResource::collection($query->get());
    }

    /** Add an entry (a name that already exists, in any spelling, is refused). */
    public function store(ReferenceItemRequest $request, string $type, ManageReferenceData $manage): JsonResponse
    {
        $item = $manage->create(ReferenceType::from($type)->model(), $request->validated('name_ar'));

        return (new ReferenceItemResource($item))->response()->setStatusCode(201);
    }

    /** Rename an entry. */
    public function update(ReferenceItemRequest $request, string $type, string $id, ManageReferenceData $manage): ReferenceItemResource
    {
        return new ReferenceItemResource($manage->rename($this->find($type, $id), $request->validated('name_ar')));
    }

    /** Archive an entry: it leaves the lists but stays valid for existing applications. */
    public function archive(string $type, string $id, ManageReferenceData $manage): ReferenceItemResource
    {
        return new ReferenceItemResource($manage->archive($this->find($type, $id)));
    }

    /** Bring an archived entry back. */
    public function restore(string $type, string $id, ManageReferenceData $manage): ReferenceItemResource
    {
        return new ReferenceItemResource($manage->restore($this->find($type, $id)));
    }

    private function find(string $type, string $id): Model
    {
        return ReferenceType::from($type)->model()::withTrashed()->findOrFail($id);
    }
}
