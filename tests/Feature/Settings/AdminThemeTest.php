<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Per-tenant admin theme (Ethereal default / Minimalista). Stored on the tenant
 * row inside the brand policy group, validated as an enum, exposed at bootstrap
 * via /me. API shape (routes/api/v1/settings.php): POST /api/v1/settings/{group}
 * with a FLAT payload; there is no PATCH route and no nested "brand" wrapper.
 */
final class AdminThemeTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function ownerForTenant(): array
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        return [$owner, $tenant];
    }

    public function test_new_tenant_defaults_to_ethereal_theme(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('ethereal', $tenant->admin_theme);
    }

    public function test_settings_show_returns_admin_theme(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/settings'))
            ->assertOk()
            ->assertJsonPath('data.brand.admin_theme', 'ethereal');
    }

    public function test_owner_can_switch_admin_theme_to_minimal(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/settings/brand'), [
                'business_name' => $tenant->business_name,
                'admin_theme' => 'minimal',
            ])
            ->assertOk();

        $this->assertSame('minimal', $tenant->fresh()->admin_theme);
    }

    public function test_invalid_admin_theme_is_rejected(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/settings/brand'), [
                'business_name' => $tenant->business_name,
                'admin_theme' => 'neon',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_theme');
    }

    public function test_theme_change_is_isolated_per_tenant(): void
    {
        [$ownerA, $tenantA] = $this->ownerForTenant();
        [, $tenantB] = $this->ownerForTenant();

        $this->actingAs($ownerA)
            ->postJson($this->tenantUrl($tenantA, 'api/v1/settings/brand'), [
                'business_name' => $tenantA->business_name,
                'admin_theme' => 'minimal',
            ])
            ->assertOk();

        $this->assertSame('minimal', $tenantA->fresh()->admin_theme);
        $this->assertSame('ethereal', $tenantB->fresh()->admin_theme);
    }

    public function test_me_exposes_admin_theme_on_tenant_membership(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();
        $tenant->update(['admin_theme' => 'minimal']);

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.tenants.0.admin_theme', 'minimal');
    }
}
