<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Resources\UserSessionResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserSessionController extends Controller
{
    /**
     * Display a listing of the user's active sessions.
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        // LEFT JOIN so sessions without a Passport token (web-guard / Laravel session
        // auth) still appear. A session is active if its oauth token is unrevoked OR
        // it has no oauth token at all.
        $sessions = $user->sessions()
            ->leftJoin('oauth_access_tokens', 'user_sessions.token_id', '=', 'oauth_access_tokens.id')
            ->where(function ($query) {
                $query->whereNull('oauth_access_tokens.id')
                    ->orWhere('oauth_access_tokens.revoked', false);
            })
            ->select('user_sessions.*')
            ->latest('user_sessions.last_active_at')
            ->paginate(50);

        return UserSessionResource::collection($sessions);
    }

    /**
     * Revoke a specific user session.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        /** @var User $user */
        $user = $request->user();

        $session = $user->sessions()->findOrFail($id);

        // Get the associated passport token and revoke it
        $token = DB::table('oauth_access_tokens')
            ->where('id', $session->token_id)
            ->first();

        if ($token) {
            DB::table('oauth_access_tokens')
                ->where('id', $session->token_id)
                ->update(['revoked' => true]);
        } elseif (config('session.driver') === 'database') {
            // Web-guard rows store the Laravel session id in token_id: with
            // the database session driver, deleting the real sessions row
            // makes the eviction effective (EnsureSessionValid rejects the
            // next cookie request that carries this session id).
            DB::table('sessions')->where('id', $session->token_id)->delete();
        }

        // Delete the user session record
        $session->delete();

        return response()->noContent();
    }
}
