<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Regression guard for SPA cookie-session auth.
 *
 * The /api/v1 routes must run through Sanctum's EnsureFrontendRequestsAreStateful
 * middleware so that a first-party SPA request (stateful Origin) gets the
 * session/cookie/CSRF stack. Without it, Auth::attempt() during login succeeds and
 * returns 200, but no session is persisted — so the very next call (e.g. /me on a
 * full page reload) 401s and bounces the user to /login.
 *
 * These tests intentionally do NOT use actingAs(): that helper bypasses the HTTP
 * middleware stack and would mask exactly this bug.
 */
final class SpaSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A stateful Origin (matching config('sanctum.stateful')) makes the request
     * "first-party" so the session guard engages.
     *
     * @return array<string, string>
     */
    private function statefulHeaders(): array
    {
        return ['Origin' => 'http://localhost'];
    }

    public function test_login_then_me_authenticates_via_the_session_cookie(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->forTenant($tenant, role: 'owner')->create([
            'email' => 'spa@shop.sv',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'spa@shop.sv',
            'password' => 'Password1',
        ], $this->statefulHeaders())->assertStatus(200);

        // The session established by login must authenticate the next request
        // WITHOUT re-sending credentials and WITHOUT actingAs().
        $this->getJson('/api/v1/me', $this->statefulHeaders())
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'spa@shop.sv');
    }

    public function test_api_middleware_group_includes_sanctum_stateful_middleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $apiGroup = $router->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            $apiGroup,
            'The api middleware group must include EnsureFrontendRequestsAreStateful for SPA cookie auth.',
        );
    }

    public function test_me_still_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/me', $this->statefulHeaders())
            ->assertStatus(401);
    }
}
