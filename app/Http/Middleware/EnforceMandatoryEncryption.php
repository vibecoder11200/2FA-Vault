<?php

namespace App\Http\Middleware;

use App\Services\EncryptionService;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class EnforceMandatoryEncryption
{
    public function __construct(protected EncryptionService $encryptionService)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Auth::guest()) {
            return $next($request);
        }

        $user = Auth::user();

        if (! $this->encryptionService->isEncryptionRequired($user)) {
            return $next($request);
        }

        if (
            $request->routeIs('user.show')
            || str_starts_with($request->path(), 'api/v1/user/preferences')
            || str_starts_with($request->path(), 'api/v1/encryption/')
        ) {
            return $next($request);
        }

        return response()->json([
            'message' => 'End-to-end encryption setup is required for this account.',
            'e2ee_required' => true,
        ], Response::HTTP_FORBIDDEN);
    }
}
