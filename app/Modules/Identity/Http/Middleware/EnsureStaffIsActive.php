<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\StaffUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token stays valid after its owner is deactivated unless we check on every request.
 * Must run after auth:staff.
 */
class EnsureStaffIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof StaffUser || !$user->is_active) {
            throw new AuthenticationException();
        }

        return $next($request);
    }
}
