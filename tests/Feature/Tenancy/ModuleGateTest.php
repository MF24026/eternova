<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * The EnsureModuleEnabled middleware makes a giro-disabled module unreachable via the
 * API, not merely hidden in the nav.
 */
final class ModuleGateTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private function owner(string $giro): array
    {
        $tenant = Tenant::factory()->create(['business_type' => $giro]);
        app()->instance('currentTenant', $tenant);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        return [$owner, $tenant];
    }

    public function test_disabled_module_returns_403_on_api(): void
    {
        [$owner, $tenant] = $this->owner('ropa_boutique'); // quotations OFF

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/quotations'))
            ->assertStatus(403);
    }

    public function test_enabled_module_is_reachable(): void
    {
        [$owner, $tenant] = $this->owner('floreria_regalos'); // quotations ON

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/quotations'))
            ->assertOk();
    }
}
