<?php

namespace Tests\Api\v1;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * Breach monitoring endpoints: opt-in gate on email check, no-gate on service check.
 */
class BreachTest extends FeatureTestCase
{
    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $user;

    protected function setUp() : void
    {
        parent::setUp();

        config(['services.hibp.key' => 'test-key']);
        config(['services.hibp.base_url' => 'https://haveibeenpwned.com/api/v3']);

        Http::fake([
            'haveibeenpwned.com/api/v3/breachedaccount/*' => Http::response([['Name' => 'Adobe']], 200),
            'haveibeenpwned.com/api/v3/breaches'          => Http::response([
                ['Name' => 'Adobe', 'Title' => 'Adobe', 'Domain' => 'adobe.com', 'PwnCount' => 1, 'BreachDate' => '2013-10-04'],
            ], 200),
        ]);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function test_check_email_forbidden_when_opt_in_disabled() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('POST', '/api/v1/breach/check-email', ['email' => 'me@example.com'])
            ->assertForbidden();
    }

    #[Test]
    public function test_check_email_works_when_opted_in() : void
    {
        // Set the preference the way the API does (JSON dot-path on the cast).
        $this->user['preferences->breachMonitoring'] = true;
        $this->user->save();

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('POST', '/api/v1/breach/check-email', ['email' => 'pwned@example.com'])
            ->assertOk()
            ->assertJsonFragment(['breached' => true]);
    }

    #[Test]
    public function test_check_service_does_not_require_opt_in() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/breach/check-service?service=adobe.com')
            ->assertOk()
            ->assertJsonFragment(['breached' => true]);
    }

    #[Test]
    public function test_check_service_requires_service_param() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/breach/check-service')
            ->assertStatus(422);
    }

    #[Test]
    public function test_breach_endpoints_require_authentication() : void
    {
        $this->json('POST', '/api/v1/breach/check-email', ['email' => 'a@b.com'])->assertUnauthorized();
        $this->json('GET', '/api/v1/breach/check-service?service=x')->assertUnauthorized();
    }
}
