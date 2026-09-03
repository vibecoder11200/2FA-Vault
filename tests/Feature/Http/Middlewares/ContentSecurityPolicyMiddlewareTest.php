<?php

namespace Tests\Feature\Http\Middlewares;

use App\Http\Middleware\AddContentSecurityPolicyHeaders;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * ContentSecurityPolicyMiddlewareTest test class
 */
#[CoversClass(AddContentSecurityPolicyHeaders::class)]
class ContentSecurityPolicyMiddlewareTest extends FeatureTestCase
{
    #[Test]
    public function test_csp_headers_present_on_web_routes_when_enabled() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $response->assertHeader('Content-Security-Policy');
    }

    #[Test]
    public function test_csp_headers_absent_when_content_security_policy_disabled() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', false);

        $response = $this->get('/');

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    #[Test]
    public function test_csp_includes_base_uri_self_directive() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    #[Test]
    public function test_csp_includes_form_action_self_directive() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    #[Test]
    public function test_csp_includes_frame_ancestors_none_directive() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    #[Test]
    public function test_api_routes_get_minimal_csp() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        /**
         * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
         */
        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/encryption/status');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    #[Test]
    public function test_api_routes_include_x_content_type_options_nosniff() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        /**
         * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
         */
        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/encryption/status');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function test_web_csp_does_not_include_default_src_none() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        // Web routes use "default-src 'self'", not "default-src 'none'"
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString("default-src 'none'", $csp);
    }

    #[Test]
    public function test_web_csp_includes_script_src_with_nonce() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'nonce-[a-zA-Z0-9]+'/U", $csp);
    }

    #[Test]
    public function test_web_csp_includes_object_src_none() : void
    {
        Config::set('2fauth.config.contentSecurityPolicy', true);

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("object-src 'none'", $csp);
    }
}
