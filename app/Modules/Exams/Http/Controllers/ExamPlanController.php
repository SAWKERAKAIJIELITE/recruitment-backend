<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\ManageExamPlans;
use App\Modules\Exams\Enums\ExamPlanStatus;
use App\Modules\Exams\Http\Requests\StoreExamPlanRequest;
use App\Modules\Exams\Http\Requests\UpdateExamPlanRequest;
use App\Modules\Exams\Http\Resources\ExamPlanResource;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Exam plans: what a job position is examined on (D-053). A job position has at most one published version and
 * at most one draft. Reading: exam_bank.manage, candidates.manage or reports.view. Changing: exam_bank.manage.
 */
class ExamPlanController extends Controller
{
    /**
     * List plan versions
     *
     * Newest first. Filter by `job_position_id` and `status` (`draft`, `published`, `retired`).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'job_position_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ExamPlanStatus::class)],
        ]);

        $plans = ExamPlanVersion::query()
            ->with(ExamPlanResource::RELATIONS)
            ->when(isset($filters['job_position_id']), fn($query) => $query->where('job_position_id', $filters['job_position_id']))
            ->when(isset($filters['status']), fn($query) => $query->where('status', $filters['status']))
            ->orderBy('job_position_id')
            ->orderByDesc('version')
            ->paginate(25);

        return ExamPlanResource::collection($plans);
    }

    /** One plan version with its subjects and parts. */
    public function show(ExamPlanVersion $plan): ExamPlanResource
    {
        return new ExamPlanResource($plan->load(ExamPlanResource::RELATIONS));
    }

    /**
     * Start a draft
     *
     * A copy of `copy_from`, else of the job position's published version, else empty. Refused (422) while the
     * job position already has a draft.
     */
    public function store(StoreExamPlanRequest $request, ManageExamPlans $plans): JsonResponse
    {
        $draft = $plans->createDraft(
            JobPosition::withTrashed()->findOrFail($request->validated('job_position_id')),
            $request->user(),
            $request->validated('copy_from') === null ? null : ExamPlanVersion::query()->findOrFail($request->validated('copy_from')),
        );

        return ExamPlanResource::make($draft->load(ExamPlanResource::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Replace a draft
     *
     * Send the whole draft: the session duration and every subject, in exam order. A draft may be incomplete;
     * publishing checks it. Only drafts change (422 otherwise).
     */
    public function update(UpdateExamPlanRequest $request, ExamPlanVersion $plan, ManageExamPlans $plans): ExamPlanResource
    {
        $draft = $plans->replaceDraft($plan, $request->validated(), $request->user());

        return new ExamPlanResource($draft->load(ExamPlanResource::RELATIONS));
    }

    /**
     * What keeps a draft from being published
     *
     * The same checks as publishing, without publishing: `problems` is keyed like the request fields and empty when
     * the draft is ready (also used to see what the question bank still lacks).
     */
    public function readiness(ExamPlanVersion $plan, ManageExamPlans $plans): JsonResponse
    {
        return response()->json(['data' => ['ready' => ($problems = $plans->problems($plan)) === [], 'problems' => (object) $problems]]);
    }

    /** Delete a draft. Published and retired versions are kept for ever. */
    public function destroy(Request $request, ExamPlanVersion $plan, ManageExamPlans $plans): Response
    {
        $plans->deleteDraft($plan, $request->user());

        return response()->noContent();
    }

    /**
     * Publish a draft
     *
     * Freezes it under the current pass policy and retires the version it replaces. 422 lists everything still
     * missing: duration, questions per difficulty, archived subjects or parts, and questions the bank lacks.
     */
    public function publish(Request $request, ExamPlanVersion $plan, ManageExamPlans $plans): ExamPlanResource
    {
        return new ExamPlanResource($plans->publish($plan, $request->user())->load(ExamPlanResource::RELATIONS));
    }
}
