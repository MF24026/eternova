<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * business_type is the single source of truth for a tenant's vertical (giro): it
 * persists on the tenant and derives the starter catalog seeded at signup.
 */
final class BusinessTypeTest extends TestCase
{
    use RefreshDatabase;

    private function basico(): Plan
    {
        return Plan::factory()->create([
            'slug' => 'basico',
            'is_active' => true,
            'price_monthly_cents' => 0,
            'price_yearly_cents' => 0,
            'currency' => 'USD',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'mi-tienda',
            'name' => 'Mi Tienda',
            'business_name' => 'Mi Tienda SV',
            'country_code' => 'SV',
            'currency' => 'USD',
            'language' => 'es',
            'timezone' => 'America/El_Salvador',
        ], $overrides);
    }

    public function test_new_tenant_defaults_to_otro(): void
    {
        $this->assertSame('otro', Tenant::factory()->create()->business_type);
    }

    public function test_creating_a_tenant_persists_business_type_and_seeds_matching_catalog(): void
    {
        $this->basico();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['business_type' => 'floreria_regalos']))
            ->assertStatus(201);

        $tenant = Tenant::withoutGlobalScopes()->where('slug', 'mi-tienda')->firstOrFail();
        $this->assertSame('floreria_regalos', $tenant->business_type);
        // floreria_regalos maps to the 'floreria' starter template -> catalog seeded.
        $this->assertGreaterThan(0, Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_giro_without_starter_template_seeds_no_catalog(): void
    {
        $this->basico();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['business_type' => 'ropa_boutique']))
            ->assertStatus(201);

        $tenant = Tenant::withoutGlobalScopes()->where('slug', 'mi-tienda')->firstOrFail();
        $this->assertSame('ropa_boutique', $tenant->business_type);
        $this->assertSame(0, Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_invalid_business_type_is_rejected(): void
    {
        $this->basico();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['business_type' => 'gastronomia']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('business_type');
    }
}
