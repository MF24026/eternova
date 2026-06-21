<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Verifies App\Http\Middleware\SecurityHeaders attaches the defensive response
 * headers, and that the two conditional headers behave per environment:
 *
 *   - HSTS is emitted only over TLS (never on plain-http, which would brick dev).
 *   - CSP is strict in production (per-request Vite nonce, no inline scripts) and
 *     relaxed elsewhere so Debugbar and the Vite dev client keep working.
 *
 * The /api/v1/health route is used because it is unauthenticated and hits no DB,
 * so the test isolates the middleware behaviour.
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_static_security_headers_are_present_on_every_response(): void
    {
        $response = $this->getJson('http://localhost/api/v1/health');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');
    }

    public function test_hsts_is_absent_over_plain_http(): void
    {
        $response = $this->getJson('http://localhost/api/v1/health');

        $response->assertOk();
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_emitted_over_tls(): void
    {
        $response = $this->getJson('https://localhost/api/v1/health');

        $response->assertOk();
        $this->assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $response->headers->get('Strict-Transport-Security')
        );
    }

    public function test_csp_is_relaxed_outside_production_so_dev_tooling_works(): void
    {
        $response = $this->getJson('http://localhost/api/v1/health');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_csp_allows_the_wompi_qr_image_host(): void
    {
        $response = $this->getJson('http://localhost/api/v1/health');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        // The recurring-affiliation QR is served from Wompi's blob storage; the img-src must allow it.
        $this->assertStringContainsString('https://wompistorage.blob.core.windows.net', $csp);
    }

    public function test_csp_is_strict_with_a_nonce_in_production(): void
    {
        $this->app['env'] = 'production';

        $response = $this->getJson('http://localhost/api/v1/health');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/]+'/", $csp);
        $this->assertStringNotContainsString("'unsafe-inline' 'unsafe-eval'", $csp);
    }

    public function test_can_be_disabled_via_config(): void
    {
        config(['security.headers.csp_enabled' => false]);

        $response = $this->getJson('http://localhost/api/v1/health');

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        // Static headers stay on — only CSP is gated by this flag.
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
}
