<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_public_pdf_diagnostic_route_does_not_exist(): void
    {
        $this->get('/test-pdf')->assertNotFound();
    }

    public function test_responses_include_baseline_security_headers(): void
    {
        $this->get('/login')
            ->assertSuccessful()
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; frame-ancestors 'self'; object-src 'none'")
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_secure_production_response_includes_configured_hsts_policy(): void
    {
        config()->set([
            'security.hsts.enabled' => true,
            'security.hsts.max_age' => 31536000,
            'security.hsts.include_subdomains' => true,
            'security.hsts.preload' => true,
        ]);

        $this->get('https://localhost/login')
            ->assertSuccessful()
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload',
            );
    }

    public function test_hsts_is_not_sent_over_an_insecure_connection(): void
    {
        config()->set('security.hsts.enabled', true);

        $this->get('http://localhost/login')
            ->assertSuccessful()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_security_headers_can_be_disabled_for_emergency_compatibility(): void
    {
        config()->set('security.headers.enabled', false);

        $this->get('/login')
            ->assertSuccessful()
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('X-Content-Type-Options');
    }
}
