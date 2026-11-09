<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contracts\Actions\ManageVacancies;
use App\Modules\Contracts\Http\Requests\UploadVacancyPlanRequest;
use App\Modules\Contracts\Http\Resources\VacancyPlanResource;
use App\Modules\Contracts\Models\VacancyPlan;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vacancies (D-088): how many first contracts each job position may get during three months. Reading: contracts.view
 * (the Contracts office, the general manager). Uploading and replacing: vacancies.manage (the system administrator).
 */
class VacancyPlanController extends Controller
{
    /** Every vacancies file, the latest period first (replaced ones too, with what replaced them), 25 per page. */
    public function index(): AnonymousResourceCollection
    {
        return VacancyPlanResource::collection(
            VacancyPlan::query()->with(VacancyPlanResource::RELATIONS)->orderByDesc('starts_on')->orderByDesc('created_at')->paginate(25),
        );
    }

    /**
     * The vacancies in force today
     *
     * The plan covering today (Damascus) with each job position's vacancies, used and left; `data` is null when
     * none covers today (no first contract can be written then).
     */
    public function current(ManageVacancies $vacancies): JsonResponse
    {
        $plan = VacancyPlan::query()->covering(BusinessDay::today())->first();

        return response()->json(['data' => $plan === null ? null : $this->detailed($plan, $vacancies)->resolve()]);
    }

    /** One vacancies file with each job position's vacancies, used and left. */
    public function show(VacancyPlan $plan, ManageVacancies $vacancies): VacancyPlanResource
    {
        return $this->detailed($plan, $vacancies);
    }

    /** The uploaded file, as a download. */
    public function original(VacancyPlan $plan): StreamedResponse
    {
        $type = str_ends_with(strtolower($plan->stored_path), '.xls') ? 'application/vnd.ms-excel' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return Storage::disk(config('contracts.vacancies_disk'))->download($plan->stored_path, $plan->original_filename, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Upload a vacancies file
     *
     * multipart/form-data: `file`, `starts_on` (default today), `notes`. The period is three months from `starts_on`
     * and must not overlap a file in force (422 naming it). The first sheet: a header row with «المسمى الوظيفي» and
     * «عدد الشواغر», then one job position per row (named as in the list) and its vacancies (0 or more). A job
     * position missing from the file has no vacancy. Any problem refuses the file, with every row's problem (422).
     */
    public function store(UploadVacancyPlanRequest $request, ManageVacancies $vacancies): JsonResponse
    {
        $plan = $vacancies->upload($request->file('file'), $request->validated('starts_on'), $request->validated('notes'), $request->user());

        return $this->detailed($plan, $vacancies)->response()->setStatusCode(201);
    }

    /**
     * Replace a vacancies file
     *
     * A corrected file for the same period, while that period has not ended: the new numbers apply at once, and the
     * contracts already written count against them. The replaced file stays in the list. `file`, `notes`.
     */
    public function replace(UploadVacancyPlanRequest $request, VacancyPlan $plan, ManageVacancies $vacancies): JsonResponse
    {
        $replacement = $vacancies->replace($plan, $request->file('file'), $request->validated('notes'), $request->user());

        return $this->detailed($replacement, $vacancies)->response()->setStatusCode(201);
    }

    private function detailed(VacancyPlan $plan, ManageVacancies $vacancies): VacancyPlanResource
    {
        $plan->load([...VacancyPlanResource::RELATIONS, 'items.jobPosition']);
        $plan->setAttribute('usage', $vacancies->used($plan));

        return new VacancyPlanResource($plan);
    }
}
