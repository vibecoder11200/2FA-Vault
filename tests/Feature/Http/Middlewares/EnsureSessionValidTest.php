<?php

namespace Tests\Feature\Http\Middlewares;

use App\Http\Middleware\EnsureSessionValid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * EnsureSessionValidTest (A3, RT2): the middleware must only affect
 * web-guard cookie sessions, must reject revoked session ids on the next
 * cookie request, and must no-op on the file driver.
 */
#[CoversClass(EnsureSessionValid::class)]
class EnsureSessionValidTest extends FeatureTestCase
{
    private const PASSWORD = 'password';

    #[Test]
    public function test_pat_request_is_unaffected_by_the_middleware()
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');

        // Bearer-token traffic passes through untouched.
        $this->getJson('/api/v1/twofaccounts')->assertOk();

        // Even when no session row exists for a (hypothetical) session id.
        $this->assertSame(0, DB::table('sessions')->count());
    }

    #[Test]
    public function test_revoked_session_id_is_rejected_on_next_cookie_request()
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['password' => self::PASSWORD]);

        // Real login: creates the Laravel session row + the user_sessions
        // bookkeeping row.
        $this->json('POST', '/user/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $sessionId = DB::table('sessions')->pluck('id')->first();
        $this->assertNotNull($sessionId, 'login should have persisted a session row');
        $this->assertDatabaseHas('user_sessions', ['token_id' => $sessionId]);

        // Authenticated cookie request passes while the session row exists.
        $this->getJson('/oauth/personal-access-tokens')->assertOk();

        // Revoke: delete the session row (what UserSessionController::destroy
        // does under the database driver). The follow-up request must present
        // the (encrypted) session cookie explicitly — the test harness does
        // not replay response cookies across requests on its own.
        DB::table('sessions')->where('id', $sessionId)->delete();

        $this->withCredentials()
            ->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/oauth/personal-access-tokens')
            ->assertUnauthorized();
        $this->assertGuest('web-guard');
    }

    #[Test]
    public function test_middleware_noops_on_file_driver()
    {
        // Default testing driver is file (no sessions table queried).
        config(['session.driver' => 'file']);

        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->json('POST', '/user/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        // No sessions table row exists, request must still pass.
        $this->assertSame(0, DB::table('sessions')->count());
        $this->getJson('/oauth/personal-access-tokens')->assertOk();
    }
}
