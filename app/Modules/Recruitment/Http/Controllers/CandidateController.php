<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recruitment\Actions\ChangeCandidateActivation;
use App\Modules\Recruitment\Actions\UpdateCandidate;
use App\Modules\Recruitment\Http\Requests\DeactivateCandidateRequest;
use App\Modules\Recruitment\Http\Requests\UpdateCandidateRequest;
use App\Modules\Recruitment\Http\Resources\CandidateDetailResource;
use App\Modules\Recruitment\Http\Resources\CandidateSummaryResource;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Support\ArabicText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The people (D-049). People are created by opening an application or by an import, never on their own. */
class CandidateController extends Controller
{
    /**
     * Search people
     *
     * `q` matches a national number exactly (any digit style) or part of a name in any spelling. Newest first,
     * 25 per page, each with the number of applications and the live one, if any.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'birth_place_id' => ['nullable', 'integer'],
            'has_live_application' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
        ]);

        $people = Candidate::query()
            ->with(['birthPlace', 'liveApplication'])
            ->withCount('applications')
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%' . addcslashes(ArabicText::searchKey($q), '\\%_') . '%';

                $query->where(fn($inner) => $inner
                    ->where('national_number', NationalNumber::normalize($q))
                    ->orWhere('name_search_key', 'like', $like));
            })
            ->when(isset($filters['is_active']), fn($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($filters['birth_place_id'] ?? null, fn($query, $id) => $query->where('birth_place_id', $id))
            ->when(isset($filters['has_live_application']), fn($query) => $request->boolean('has_live_application')
                ? $query->whereHas('liveApplication')
                : $query->whereDoesntHave('liveApplication'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return CandidateSummaryResource::collection($people);
    }

    /** One person with all their applications. */
    public function show(Candidate $candidate): CandidateDetailResource
    {
        return new CandidateDetailResource($candidate->load(CandidateDetailResource::RELATIONS));
    }

    /**
     * Correct a person
     *
     * Send only what changes: `national_number`, `full_name`, `mother_name`, `birth_date`, `birth_place_id`,
     * `notes`. A national number already used by another person is refused (422). Audited.
     */
    public function update(UpdateCandidateRequest $request, Candidate $candidate, UpdateCandidate $update): CandidateDetailResource
    {
        $candidate = $update->execute($candidate, $request->validated(), $request->user());

        return new CandidateDetailResource($candidate->load(CandidateDetailResource::RELATIONS));
    }

    /**
     * Deactivate a person
     *
     * A deactivated person cannot be nominated (API or import). Refused (422) while the person has a live
     * application: close it first.
     */
    public function deactivate(DeactivateCandidateRequest $request, Candidate $candidate, ChangeCandidateActivation $activation): CandidateDetailResource
    {
        $candidate = $activation->deactivate($candidate, $request->user(), $request->validated('reason'));

        return new CandidateDetailResource($candidate->load(CandidateDetailResource::RELATIONS));
    }

    /** Reactivate a person. */
    public function activate(Request $request, Candidate $candidate, ChangeCandidateActivation $activation): CandidateDetailResource
    {
        return new CandidateDetailResource($activation->activate($candidate, $request->user())->load(CandidateDetailResource::RELATIONS));
    }
}
