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
 * The audit log (D-078). `audit.view` reads every event; `audit.view_related` reads the operations only (not
 * access management, devices or logins). Nothing here changes or deletes an event.
 */
class AuditEventController extends Controller
{
    /**
     * List audit events
     *
     * Newest first, 50 per page. `event`: an event or its beginning (`interview.` for every interview event);
     * `actor_id`: a staff user; `related_id`: a record's id found in the event's details (an application, a
     * booking, a user, ...); `category`: operations or security; `from` / `to` (YYYY-MM-DD, UTC days, included).
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

        $events = AuditEvent::query()
            ->with('actor')
            ->when($relatedOnly, fn ($query) => $query->where('category', 'operations'))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['event'] ?? null, fn ($query, $event) => str_ends_with($event, '.')
                ? $query->where('event', 'like', $event.'%')
                : $query->where('event', $event))
            ->when($filters['actor_id'] ?? null, fn ($query, $id) => $query->where('actor_id', $id))
            ->when($filters['related_id'] ?? null, fn ($query, $id) => $query->whereRaw('EXISTS (SELECT 1 FROM jsonb_each_text(audit_events.context) AS c(key, value) WHERE c.value = ?)', [$id]))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->where('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->where('created_at', '<', Carbon::parse($date)->addDay()->toDateString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return AuditEventResource::collection($events);
    }
}
