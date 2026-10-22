<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the request when the staff user holds AT LEAST ONE of the listed permissions.
 * Usage: ->middleware(RequireAnyPermission::class.':reports.view,candidates.manage')
 * (the built-in `can:` middleware can only demand one). Must run after auth:staff.
 */
class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_unless($user !== null && Gate::forUser($user)->any($permissions), 403);

        return $next($request);
    }
}
