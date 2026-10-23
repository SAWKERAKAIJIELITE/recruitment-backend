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
 * The managed lists. {type} is one of: districts, areas, nominating-entities, job-positions,
 * application-categories, academic-qualifications, specializations, marital-statuses, health-conditions.
 * Any signed-in staff user can read them; changing them needs candidates.manage.
 */
class ReferenceDataController extends Controller
{
    /**
     * List entries
     *
     * Archived entries are hidden unless `include_archived=1`. For areas, `district_id` limits the list
     * to one district.
     */
    public function index(Request $request, string $type): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'include_archived' => ['nullable', 'boolean'],
            'district_id' => ['nullable', 'integer'],
        ]);

        $referenceType = ReferenceType::from($type);

        $query = $referenceType->model()::query()
            ->when($request->boolean('include_archived'), fn($query) => $query->withTrashed())
            ->when(
                $referenceType->isScopedToDistrict() && isset($filters['district_id']),
                fn($query) => $query->where('district_id', $filters['district_id']),
            )
            ->orderBy('name_key');

        return ReferenceItemResource::collection($query->get());
    }

    /**
     * Add an entry
     *
     * A name that already exists, in any spelling, is refused (422), also when that entry is archived:
     * restore it instead. A new area needs `district_id`.
     */
    public function store(ReferenceItemRequest $request, string $type, ManageReferenceData $manage): JsonResponse
    {
        $item = $manage->create(
            ReferenceType::from($type),
            $request->validated('name_ar'),
            $request->validated('district_id') === null ? null : (int) $request->validated('district_id'),
        );

        return (new ReferenceItemResource($item))->response()->setStatusCode(201);
    }

    /** Rename an entry. */
    public function update(ReferenceItemRequest $request, string $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $referenceType = ReferenceType::from($type);

        return new ReferenceItemResource($manage->rename($referenceType, $this->find($referenceType, $id), $request->validated('name_ar')));
    }

    /** Archive an entry: it leaves the lists but stays valid for existing applications. */
    public function archive(string $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        return new ReferenceItemResource($manage->archive($this->find(ReferenceType::from($type), $id)));
    }

    /** Bring an archived entry back. */
    public function restore(string $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        return new ReferenceItemResource($manage->restore($this->find(ReferenceType::from($type), $id)));
    }

    private function find(ReferenceType $type, int $id): Model
    {
        return $type->model()::withTrashed()->findOrFail($id);
    }
}
