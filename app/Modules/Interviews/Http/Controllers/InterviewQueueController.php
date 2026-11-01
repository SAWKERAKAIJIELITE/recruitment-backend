<?php

namespace App\Modules\Interviews\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Interviews\Http\Resources\InterviewQueueEntryResource;
use App\Modules\Interviews\Queries\InterviewQueue;
use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The interview queue (D-062). Reading: interviews.manage, candidates.manage or reports.view. */
class InterviewQueueController extends Controller
{
    /**
     * Interview queue
     *
     * Applications awaiting their interview with none booked, in the order of `priority` (first rule first):
     * `priority[]=specialization_id:5,2` puts those entries first, in that order (also job_position_id,
     * nominating_entity_id, application_category_id, academic_qualification_id, residence_district_id,
     * residence_area_id); `priority[]=quran_parts:desc` sorts by a value (quran_parts, studying_years, age,
     * waiting_since, nominated_at). Ties: longest waiting first. Takes the filters of the applications list (not
     * `status`, `live`, `sort`). Book the ones chosen with POST /interview-sittings/{id}/bookings.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(InterviewQueue::rules());
        $page = InterviewQueue::query($filters)
            ->with(ApplicationSummaryResource::RELATIONS)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        $first = ($page->currentPage() - 1) * $page->perPage();
        $page->getCollection()->each(fn($application, $index) => $application->setAttribute('queue_position', $first + $index + 1));

        return InterviewQueueEntryResource::collection($page);
    }
}
