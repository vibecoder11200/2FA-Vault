<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MetricsAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $allowedIps = collect(explode(',', config('metrics.allowed_ips', '')))
            ->filter()
            ->map(fn ($ip) => trim($ip));

        $metricsToken = config('metrics.token');

        // Check IP allowlist
        if ($allowedIps->isNotEmpty() && $allowedIps->contains($request->ip())) {
            return $next($request);
        }

        // Check Bearer token (timing-safe comparison, A11)
        if ($metricsToken && $request->bearerToken() !== null && hash_equals($metricsToken, $request->bearerToken())) {
            return $next($request);
        }

        // Unauthorized
        abort(403, 'Unauthorized access to metrics endpoint');
    }
}
