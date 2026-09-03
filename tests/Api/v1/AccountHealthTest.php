<?php

namespace Tests\Api\v1;

use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * Account health scoring endpoints: single-account score + vault summary,
 * with ownership authorization enforced.
 */
class AccountHealthTest extends FeatureTestCase
{
    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $user;

    protected $anotherUser;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user        = User::factory()->create();
        $this->anotherUser = User::factory()->create();
    }

    #[Test]
    public function test_show_returns_score_for_owned_account() : void
    {
        $account = TwoFAccount::factory()->for($this->user)->create([
            'algorithm'    => 'sha256',
            'digits'       => 6,
            'period'       => 30,
            'last_used_at' => Carbon::now()->subDays(5),
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/twofaccounts/' . $account->id . '/health')
            ->assertOk()
            ->assertJsonStructure([
                'id', 'algorithm_score', 'digits_score', 'freshness_score',
                'period_score', 'server_total', 'grade',
            ])
            ->assertJsonFragment(['grade' => 'A']);
    }

    #[Test]
    public function test_show_forbidden_for_other_users_account() : void
    {
        $account = TwoFAccount::factory()->for($this->anotherUser)->create();

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/twofaccounts/' . $account->id . '/health')
            ->assertForbidden();
    }

    #[Test]
    public function test_summary_aggregates_only_users_accounts() : void
    {
        TwoFAccount::factory()->for($this->user)->create([
            'algorithm' => 'sha256', 'last_used_at' => Carbon::now()->subDays(5),
        ]);
        TwoFAccount::factory()->for($this->user)->create([
            'algorithm' => 'md5', 'last_used_at' => Carbon::now()->subDays(400),
        ]);
        // Another user's account must not appear
        TwoFAccount::factory()->for($this->anotherUser)->create([
            'algorithm' => 'md5', 'last_used_at' => Carbon::now()->subDays(400),
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/twofaccounts/health/summary')
            ->assertOk()
            ->assertJsonFragment(['total' => 2])
            ->assertJsonStructure([
                'total', 'grade_counts', 'average_server_total', 'weak_account_ids',
            ]);
    }

    #[Test]
    public function test_summary_requires_authentication() : void
    {
        $this->json('GET', '/api/v1/twofaccounts/health/summary')->assertUnauthorized();
    }

    #[Test]
    public function test_health_route_not_captured_by_api_resource_show() : void
    {
        // The /health/summary literal must resolve to the controller, not 404
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/twofaccounts/health/summary')
            ->assertOk();
    }
}
