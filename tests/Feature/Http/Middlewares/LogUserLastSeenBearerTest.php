<?php

namespace Tests\Feature\Http\Middlewares;

use App\Http\Middleware\LogUserLastSeen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * B16: bearer-token (extension/CLI) activity must also update
 * users.last_seen_at — otherwise API-only owners falsely trigger the
 * emergency dead man's switch.
 */
#[CoversClass(LogUserLastSeen::class)]
class LogUserLastSeenBearerTest extends FeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_bearer_token_activity_updates_last_seen_at() : void
    {
        $user = User::factory()->create([
            'last_seen_at' => null,
        ]);

        $request = Request::create('/api/v1/twofaccounts', 'GET');
        $request->headers->set('Authorization', 'Bearer a-real-bearer-token-value');
        $this->app->instance('request', $request);
        \Illuminate\Support\Facades\Auth::shouldUse('api-guard');
        \Illuminate\Support\Facades\Auth::setUser($user);

        $response = (new LogUserLastSeen)->handle($request, fn () => response()->noContent(), 'api-guard');

        $this->assertEquals(204, $response->getStatusCode());

        $user->refresh();
        $this->assertNotNull($user->last_seen_at);
        $this->assertTrue(now()->diffInMinutes($user->last_seen_at) < 5);
    }

    #[Test]
    public function test_api_activity_updates_last_seen_at() : void
    {
        // End-to-end: an api-guard-authenticated request updates the
        // last_seen_at column (dead man's switch input).
        $user = User::factory()->create([
            'last_seen_at' => null,
        ]);

        \Laravel\Passport\Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this->getJson('/api/v1/twofaccounts')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_seen_at);
    }
}
