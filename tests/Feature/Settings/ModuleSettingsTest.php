<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * The `modules` settings group reads the effective (giro default composed with
 * overrides) flags and writes overrides into tenants.module_overrides.
 */
final class ModuleSettingsTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function owner(string $giro): array
    {
        $tenant = Tenant::factory()->create(['business_type' => $giro]);
        app()->instance('currentTenant', $tenant);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        return [$owner, $tenant];
    }

    public function test_show_returns_effective_module_flags(): void
    {
        [$owner, $tenant] = $this->owner('floreria_regalos');

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/settings'))
            ->assertOk()
            ->assertJsonPath('data.modules.reservations', true)
            ->assertJsonPath('data.modules.quotations', true);
    }

    public function test_owner_can_override_a_module(): void
    {
        [$owner, $tenant] = $this->owner('floreria_regalos');

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/settings/modules'), ['quotations' => false])
            ->assertOk();

        $this->assertFalse((bool) ($tenant->fresh()->module_overrides['quotations'] ?? true));
    }

    public function test_override_is_isolated_per_tenant(): void
    {
        [$ownerA, $tenantA] = $this->owner('floreria_regalos');
        [, $tenantB] = $this->owner('floreria_regalos');

        $this->actingAs($ownerA)
            ->postJson($this->tenantUrl($tenantA, 'api/v1/settings/modules'), ['quotations' => false])
            ->assertOk();

        $this->assertFalse((bool) ($tenantA->fresh()->module_overrides['quotations'] ?? true));
        $this->assertNull($tenantB->fresh()->module_overrides);
    }
}
