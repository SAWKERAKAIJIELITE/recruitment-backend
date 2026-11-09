<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Resources\AuditEventResource;
use App\Modules\Audit\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The audit log (D-078). `audit.view` reads every event; `audit.view_related` reads the operations of the user's own
 * sections only (D-086): not access management, devices or logins, and not another office's operations. Nothing
 * here changes or deletes an event.
 */
class AuditEventController extends Controller
{
    /** A section's view permission => the beginnings of its events. */
    public const SECTIONS = [
        'applications.view' => ['application.', 'candidate.', 'import.', 'report.results_', 'report.conscription_list'],
        'exams.view' => ['exam.', 'exam_'],
        'interviews.view' => ['interview.', 'interview_'],
        'contracts.view' => ['contract.', 'vacancy_plan.'],
        'documents.view' => ['document.', 'document_type.'],
    ];

    /**
     * List audit events
     *
     * Newest first, 50 per page. `event`: an event or its beginning (`interview.` for every interview event);
     * `actor_id`: a staff user; `related_id`: a record's id the event is about (its details' `id`, `*_id` and
     * `*_ids`: an application, a booking, a user, ...; or the acting candidate); `category`: operations or security; `from` / `to` (YYYY-MM-DD, UTC days, included).
     * With `audit.view_related` only, the operations of the user's sections (Recruitment: applications, people,
     * imports, results lists; Exams and Interviews: exams and interviews; Contracts: contracts and vacancies;
     * Documenting: documents).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:100', 'regex:/^[a-z_.]+$/'],
            'actor_id' => ['nullable', 'uuid'],
            'related_id' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', Rule::in(['operations', 'security'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $relatedOnly = ! $request->user()->hasPermission('audit.view');
        $prefixes = collect(self::SECTIONS)->filter(fn(array $prefixes, string $permission) => $request->user()->hasPermission($permission))->flatten()->all();
        $like = fn(string $prefix) => addcslashes($prefix, '\\%_') . '%';

        // `isset`, not when()'s truthiness: a filter of "0" still filters.
        $given = fn(string $filter) => isset($filters[$filter]);

        $events = AuditEvent::query()
            ->with('actor')
            ->when(
                $relatedOnly,
                fn($query) => $query->where('category', 'operations')
                    ->where(function ($query) use ($prefixes, $like) {
                        $query->whereRaw('false');

                        foreach ($prefixes as $prefix) {
                            $query->orWhere('event', 'like', $like($prefix));
                        }
                    })
            )
            ->when($given('category'), fn($query) => $query->where('category', $filters['category']))
            ->when($given('event'), fn($query) => str_ends_with($filters['event'], '.')
                ? $query->where('event', 'like', $like($filters['event']))
                : $query->where('event', $filters['event']))
            ->when($given('actor_id'), fn($query) => $query->where('actor_id', $filters['actor_id']))
            // Indexed (GIN on related_ids).
            ->when($given('related_id'), fn($query) => $query->whereRaw('related_ids @> ARRAY[?]::text[]', [$filters['related_id']]))
            ->when($given('from'), fn($query) => $query->where('created_at', '>=', $filters['from']))
            ->when($given('to'), fn($query) => $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->toDateString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return AuditEventResource::collection($events);
    }
}
