<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Modules\Plans\Models\Plan;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET /api/v1/plans
 *
 * The endpoint is public and returns the active plans sorted by sort_order.
 * Inactive plans are excluded.
 */
final class PlansListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_plans_in_sort_order(): void
    {
        $this->seed(PlansSeeder::class);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'slug',
                        'name',
                        'description',
                        'price_monthly_cents',
                        'price_yearly_cents',
                        'currency',
                        'features',
                        'limits',
                        'sort_order',
                    ],
                ],
                'meta' => ['request_id'],
            ]);

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertSame(['basico', 'pro', 'enterprise'], $slugs);
        $this->assertCount(3, $slugs);
    }

    public function test_omits_inactive_plans(): void
    {
        $this->seed(PlansSeeder::class);

        // Mark pro as inactive — it must not appear in the response
        Plan::where('slug', 'pro')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertNotContains('pro', $slugs);
        $this->assertCount(2, $slugs);
    }

    public function test_endpoint_is_public_and_requires_no_authentication(): void
    {
        $this->seed(PlansSeeder::class);

        $this->getJson('/api/v1/plans')->assertOk();
    }
}
