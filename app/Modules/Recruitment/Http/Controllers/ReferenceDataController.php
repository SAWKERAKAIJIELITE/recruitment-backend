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
use Illuminate\Validation\Rule;

/**
 * The managed lists. {type} is a ReferenceType value (an unknown one is 404): districts, areas, nominating-entities, job-positions,
 * application-categories, academic-qualifications, specializations, marital-statuses, health-conditions.
 * Any signed-in staff user can read them; changing them needs candidates.manage, except marital statuses,
 * which are seeded and read-only here (403).
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
     * Marital statuses are seeded, not added here (403).
     */
    public function store(ReferenceItemRequest $request, ReferenceType $type, ManageReferenceData $manage): JsonResponse
    {
        $this->assertEditable($type);

        $item = $manage->create(
            $type,
            $request->validated('name_ar'),
            $request->validated('district_id') === null ? null : (int) $request->validated('district_id'),
        );

        // One new entry (not a list), answered with 201 Created.
        return ReferenceItemResource::make($item)->response()->setStatusCode(201);
    }

    /** Rename an entry. */
    public function update(ReferenceItemRequest $request, ReferenceType $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $this->assertEditable($type);

        return new ReferenceItemResource($manage->rename($type, $this->find($type, $id), $request->validated('name_ar')));
    }

    /** Archive an entry: it leaves the lists but stays valid for existing applications. */
    public function archive(ReferenceType $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $this->assertEditable($type);

        return new ReferenceItemResource($manage->archive($this->find($type, $id)));
    }

    /** Bring an archived entry back. */
    public function restore(ReferenceType $type, int $id, ManageReferenceData $manage): ReferenceItemResource
    {
        $this->assertEditable($type);

        return new ReferenceItemResource($manage->restore($this->find($type, $id)));
    }

    /** Seeded lists (marital statuses) are read-only here: 403 (D-048). */
    private function assertEditable(ReferenceType $type): void
    {
        abort_unless($type->isEditableThroughApi(), 403, __('recruitment.list_is_seeded'));
    }

    private function find(ReferenceType $type, int $id): Model
    {
        return $type->model()::withTrashed()->findOrFail($id);
    }
}
