<?php

namespace App\Modules\Exams\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candidates sit their exam only from the venue's network (owner, Step 11; D-077): every candidate route, the login
 * included, answers 403 to any other address. The networks are `exams.candidate_networks` (EXAMS_CANDIDATE_NETWORKS,
 * comma-separated addresses or CIDR ranges). With none configured, candidates are refused everywhere except in the
 * local and testing environments, so a forgotten setting never opens the exam to the internet. Behind a reverse proxy,
 * set TRUSTED_PROXIES so the candidate's own address is checked, not the proxy's.
 */
class EnsureVenueNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        $networks = (array) config('exams.candidate_networks');
        $ip = (string) $request->ip();

        $allowed = $networks === []
            ? app()->environment('local', 'testing')
            : IpUtils::checkIp($ip, $networks);

        if (! $allowed) {
            Log::notice('candidate_outside_venue', ['ip' => $ip, 'path' => $request->path()]);

            abort(403, __('exams.candidate.outside_venue'));
        }

        return $next($request);
    }
}
