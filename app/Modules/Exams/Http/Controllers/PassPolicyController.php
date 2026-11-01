<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\AddPassPolicy;
use App\Modules\Exams\Http\Requests\PassPolicyRequest;
use App\Modules\Exams\Http\Resources\PassPolicyResource;
use App\Modules\Exams\Models\PassPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The pass rules, as versions (D-052, D-063). Reading: exam_bank.manage, candidates.manage or reports.view. Adding:
 * the individual permission pass_policies.manage.
 */
class PassPolicyController extends Controller
{
    /** Every version of the pass rules, newest (current) first. */
    public function index(): AnonymousResourceCollection
    {
        $policies = PassPolicy::query()->orderByDesc('version')->get();
        $policies->first()?->setAttribute('is_current', true);

        return PassPolicyResource::collection($policies);
    }

    /**
     * Add a version of the pass rules
     *
     * Individual permission pass_policies.manage. Becomes the current version: exam plans published from now on are
     * scored with it; plans already published keep theirs, so no result changes. Versions are never edited.
     */
    public function store(PassPolicyRequest $request, AddPassPolicy $add): JsonResponse
    {
        $policy = $add->execute($request->validated(), $request->user());
        $policy->setAttribute('is_current', true);

        return PassPolicyResource::make($policy)->response()->setStatusCode(201);
    }
}
