<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Brute-force / abuse rate limiting. The suite runs with high limits (phpunit.xml)
 * so it isn't throttled; these tests lower the relevant limit locally via config()
 * and flush the limiter cache to stay deterministic.
 */
final class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_is_throttled_after_too_many_attempts(): void
    {
        config(['security.rate_limits.login' => 3]);
        User::factory()->create(['email' => 'throttle@shop.sv']);

        // 3 allowed attempts (wrong password → 422 each).
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'throttle@shop.sv',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // 4th attempt is rate-limited before reaching the controller.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'throttle@shop.sv',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_throttle_is_scoped_per_email(): void
    {
        config(['security.rate_limits.login' => 2]);
        User::factory()->create(['email' => 'victim@shop.sv']);
        User::factory()->create(['email' => 'other@shop.sv']);

        // Exhaust the limit for one email.
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => 'victim@shop.sv', 'password' => 'x']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'victim@shop.sv', 'password' => 'x'])
            ->assertStatus(429);

        // A different email from the same IP is NOT locked out.
        $this->postJson('/api/v1/auth/login', ['email' => 'other@shop.sv', 'password' => 'x'])
            ->assertStatus(422);
    }

    public function test_api_routes_carry_rate_limit_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }
}
