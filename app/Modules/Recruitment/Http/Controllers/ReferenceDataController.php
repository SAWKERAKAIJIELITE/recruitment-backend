<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recruitment\Actions\ManageReferenceData;
use App\Modules\Recruitment\Enums\EditableReferenceType;
use App\Modules\Recruitment\Enums\ReferenceType;
use App\Modules\Recruitment\Http\Requests\ReferenceItemRequest;
use App\Modules\Recruitment\Http\Resources\ReferenceItemResource;
use App\Modules\Recruitment\Models\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The managed lists. {type} is a ReferenceType value (an unknown one is 404): districts, areas, nominating-entities, job-positions,
 * application-categories, academic-qualifications, specializations, marital-statuses, health-conditions.
 * Any signed-in staff user can read them; changing them needs candidates.manage. Marital statuses are
 * seeded and only read here: the change endpoints do not know them (404, D-048).
 */
class ReferenceDataController extends Controller
{
    /**
     * List entries
     *
     * Archived entries are hidden unless `include_archived` is `1` or `true`. For areas, `district_id`
     * limits the list to one district.
     */
    public function index(Request $request, ReferenceType $type): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'include_archived' => [Rule::in(['0', '1', 'true', 'false'])],
            'district_id' => ['nullable', 'integer'],
        ]);

        $query = $type->model()::query()
            ->when($request->boolean('include_archived'), fn($query) => $query->withTrashed())
            // Areas show their district: loaded once for the whole list, not once per area.
            ->when($type->isScopedToDistrict(), fn($query) => $query->with('district'))
            ->when(
                $type->isScopedToDistrict() && isset($filters['district_id']),
                fn($query) => $query->where('district_id', $filters['district_id']),
            )
            ->orderBy('name_key');

        return ReferenceItemResource::collection($query->get());
    }

    /**
     * Add an entry
     *
     * A name that already exists, in any spelling, is refused (422), also when that entry is archived:
     * restore it instead. `district_id` is required for a new area and refused for the other lists.
     */
    public function store(ReferenceItemRequest $request, EditableReferenceType $type, ManageReferenceData $manage): JsonResponse
    {
        $list = $type->type();

        $item = $manage->create(
            $list,
            $request->validated('name_ar'),
            $request->validated('district_id') === null ? null : (int) $request->validated('district_id'),
        );

        // One new entry (not a list), answered with 201 Created.
        return ReferenceItemResource::make($this->withDistrict($item))->response()->setStatusCode(201);
    }

    /** Rename an entry. */
    public function update(ReferenceItemRequest $request, EditableReferenceType $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $list = $type->type();

        return new ReferenceItemResource($this->withDistrict($manage->rename($list, $this->find($list, $id), $request->validated('name_ar'))));
    }

    /** Archive an entry: it leaves the lists but stays valid for existing applications. */
    public function archive(EditableReferenceType $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $list = $type->type();

        return new ReferenceItemResource($this->withDistrict($manage->archive($this->find($list, $id))));
    }

    /** Bring an archived entry back. */
    public function restore(EditableReferenceType $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $list = $type->type();

        return new ReferenceItemResource($this->withDistrict($manage->restore($this->find($list, $id))));
    }

    /** An area is shown with its district. */
    private function withDistrict(Model $item): Model
    {
        return $item instanceof Area ? $item->loadMissing('district') : $item;
    }

    private function find(ReferenceType $type, int $id): Model
    {
        return $type->model()::withTrashed()->findOrFail($id);
    }
}
