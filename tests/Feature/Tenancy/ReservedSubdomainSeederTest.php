<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\ReservedSubdomain;
use Database\Seeders\ReservedSubdomainsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReservedSubdomainSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserved_subdomains_seeder_inserts_all_categories(): void
    {
        $this->seed(ReservedSubdomainsSeeder::class);

        $categories = ['system', 'brand', 'trademark', 'profanity', 'regulated', 'security-sensitive'];

        foreach ($categories as $category) {
            $this->assertGreaterThan(
                0,
                ReservedSubdomain::where('category', $category)->count(),
                "Expected at least one reserved subdomain in category '{$category}' but found none."
            );
        }
    }

    public function test_seeder_inserts_expected_total_count(): void
    {
        $this->seed(ReservedSubdomainsSeeder::class);

        // The seeder defines 86 entries across all categories.
        $this->assertSame(86, ReservedSubdomain::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ReservedSubdomainsSeeder::class);
        $this->seed(ReservedSubdomainsSeeder::class); // run twice

        // Must not double-insert — unique constraint on subdomain + upsert strategy.
        $this->assertSame(86, ReservedSubdomain::count());
    }

    public function test_system_category_contains_known_entries(): void
    {
        $this->seed(ReservedSubdomainsSeeder::class);

        foreach (['admin', 'api', 'billing', 'login'] as $subdomain) {
            $this->assertNotNull(
                ReservedSubdomain::where('subdomain', $subdomain)->where('category', 'system')->first(),
                "Expected '{$subdomain}' to be reserved as system."
            );
        }
    }

    public function test_brand_category_contains_eternova_and_legacy_carol(): void
    {
        $this->seed(ReservedSubdomainsSeeder::class);

        $this->assertNotNull(
            ReservedSubdomain::where('subdomain', 'eternova')->where('category', 'brand')->first()
        );

        $this->assertNotNull(
            ReservedSubdomain::where('subdomain', 'carol-creaciones')->where('category', 'brand')->first()
        );
    }
}
