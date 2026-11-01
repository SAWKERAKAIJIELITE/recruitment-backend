<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The signed-in staff user's own notifications (D-065). Any active staff user; nobody sees another user's. */
class NotificationController extends Controller
{
    /** My notifications, newest first, 25 per page. `unread=1` for the unread ones only. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['unread' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);

        $notifications = $request->user()->notifications()
            ->when($request->boolean('unread'), fn($query) => $query->whereNull('read_at'))
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /** How many of my notifications are unread (for the bell). */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    /** Mark one of my notifications read (404 for anyone else's). */
    public function read(Request $request, string $id): NotificationResource
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return new NotificationResource($notification);
    }

    /** Mark all my notifications read. */
    public function readAll(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $count]]);
    }
}
