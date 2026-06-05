<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests for GET /api/v1/tenants/check-slug
 *
 * The endpoint is public (no auth) and returns 200 in all cases, varying
 * the {available, reason} fields based on the slug's validity.
 */
final class CheckSlugTest extends TestCase
{
    use RefreshDatabase;

    private function checkSlug(string $slug): TestResponse
    {
        return $this->getJson('/api/v1/tenants/check-slug?slug='.urlencode($slug));
    }

    public function test_returns_available_for_valid_unreserved_unused_slug(): void
    {
        $response = $this->checkSlug('mi-floristeria');

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.slug', 'mi-floristeria')
            ->assertJsonMissing(['data' => ['reason' => true]])
            ->assertJsonStructure(['data' => ['available', 'slug'], 'meta' => ['request_id']]);
    }

    public function test_returns_unavailable_with_reason_format_for_invalid_slug(): void
    {
        // Uppercase letters are not RFC 1035 compliant
        $this->checkSlug('FOO')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'format');

        // Starts with a dash
        $this->checkSlug('-foo')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'format');

        // Ends with a dash
        $this->checkSlug('foo-')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'format');

        // Too short (min is 3 characters)
        $this->checkSlug('f')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'format');
    }

    public function test_returns_unavailable_with_reason_reserved_for_admin_slug(): void
    {
        DB::table('reserved_subdomains')->insert([
            'subdomain' => 'admin',
            'category' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->checkSlug('admin')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'reserved');
    }

    public function test_returns_unavailable_with_reason_taken_for_existing_tenant_slug(): void
    {
        Tenant::factory()->create(['slug' => 'floreria-linda']);

        $this->checkSlug('floreria-linda')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'taken');
    }

    public function test_endpoint_is_public_and_requires_no_authentication(): void
    {
        // No auth headers — must still return 200, not 401
        $this->checkSlug('valido-slug')
            ->assertOk();
    }
}
