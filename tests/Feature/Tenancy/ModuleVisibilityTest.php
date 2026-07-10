<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\ModuleVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * ModuleVisibilityService composes the giro's catalog defaults with per-tenant
 * overrides. Core modules are never gated. /me exposes the resolved set.
 */
final class ModuleVisibilityTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private ModuleVisibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ModuleVisibilityService::class);
    }

    public function test_floreria_enables_reservations_and_quotations(): void
    {
        $tenant = Tenant::factory()->create(['business_type' => 'floreria_regalos']);
        $this->assertEqualsCanonicalizing(['reservations', 'quotations'], $this->service->enabledModules($tenant));
    }

    public function test_ropa_enables_no_optional_modules(): void
    {
        $tenant = Tenant::factory()->create(['business_type' => 'ropa_boutique']);
        $this->assertSame([], $this->service->enabledModules($tenant));
    }

    public function test_override_flips_a_module_regardless_of_giro(): void
    {
        $floreria = Tenant::factory()->create(['business_type' => 'floreria_regalos', 'module_overrides' => ['quotations' => false]]);
        $this->assertSame(['reservations'], $this->service->enabledModules($floreria));

        $ropa = Tenant::factory()->create(['business_type' => 'ropa_boutique', 'module_overrides' => ['reservations' => true]]);
        $this->assertSame(['reservations'], $this->service->enabledModules($ropa));
    }

    public function test_core_module_is_always_enabled(): void
    {
        $tenant = Tenant::factory()->create(['business_type' => 'ropa_boutique']);
        $this->assertTrue($this->service->isEnabled($tenant, 'products'));
    }

    public function test_me_exposes_business_type_and_enabled_modules(): void
    {
        $tenant = Tenant::factory()->create(['business_type' => 'floreria_regalos']);
        app()->instance('currentTenant', $tenant);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.tenants.0.business_type', 'floreria_regalos');

        $this->assertEqualsCanonicalizing(
            ['reservations', 'quotations'],
            $response->json('data.tenants.0.enabled_modules'),
        );
    }
}
