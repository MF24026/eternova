<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TenantProvisioningTest extends TestCase
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

    public function test_authenticated_user_can_create_tenant_and_becomes_owner(): void
    {
        $this->basico();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'mi-tienda');

        // Tenant row must exist
        $this->assertDatabaseHas('tenants', ['slug' => 'mi-tienda']);

        // Branch row must exist
        $tenant = Tenant::withoutGlobalScopes()->where('slug', 'mi-tienda')->first();
        $this->assertNotNull($tenant);
        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'is_main' => true]);

        // Subscription trialing must exist
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'status' => 'trialing',
        ]);

        // Owner link must exist in tenant_users
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_slug_validation_rejects_reserved_slugs(): void
    {
        $this->basico();
        $user = User::factory()->create();

        // Seed a reserved subdomain
        DB::table('reserved_subdomains')->insert([
            'subdomain' => 'admin',
            'category' => 'system',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['slug' => 'admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }

    public function test_slug_validation_rejects_invalid_rfc1035(): void
    {
        $this->basico();
        $user = User::factory()->create();

        // Uppercase is not allowed per RFC 1035 / slug validator
        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['slug' => 'FOO']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }

    public function test_provisioning_is_atomic_on_failure(): void
    {
        // Do NOT create any plan — provisioner will throw DomainException when it cannot
        // find the requested plan slug. This triggers a rollback inside DB::transaction().
        $user = User::factory()->create();

        $tenantCountBefore = Tenant::withoutGlobalScopes()->count();
        $branchCountBefore = Branch::withoutGlobalScopes()->count();
        $subscriptionCountBefore = Subscription::count();
        $pivotCountBefore = DB::table('tenant_users')->count();

        // 'nonexistent' passes request validation (format only) but fails in the provisioner
        $response = $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['plan_slug' => 'nonexistent']));

        // Must not be 201 — either 500 (DomainException) or 422 (validation caught it)
        $this->assertNotSame(201, $response->status());

        // Regardless of status: NO new rows must have been created
        $this->assertSame($tenantCountBefore, Tenant::withoutGlobalScopes()->count());
        $this->assertSame($branchCountBefore, Branch::withoutGlobalScopes()->count());
        $this->assertSame($subscriptionCountBefore, Subscription::count());
        $this->assertSame($pivotCountBefore, DB::table('tenant_users')->count());
    }

    public function test_tenant_provisioning_requires_authentication(): void
    {
        $this->basico();

        $this->postJson('/api/v1/tenants', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $this->basico();
        $user = User::factory()->create();

        // First tenant creation succeeds
        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['slug' => 'mi-tienda']))
            ->assertStatus(201);

        // Second tenant with same slug must fail validation
        $user2 = User::factory()->create();
        $this->actingAs($user2)
            ->postJson('/api/v1/tenants', $this->validPayload(['slug' => 'mi-tienda']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }

    public function test_provision_seeds_the_catalog_derived_from_the_business_type(): void
    {
        $this->basico();
        $user = User::factory()->create();

        // floreria_regalos maps (config/verticals.php) to the 'floreria' starter template.
        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['business_type' => 'floreria_regalos']))
            ->assertStatus(201);

        $tenant = Tenant::withoutGlobalScopes()->where('slug', 'mi-tienda')->firstOrFail();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_main', true)->firstOrFail();

        $this->assertDatabaseHas('categories', ['tenant_id' => $tenant->id, 'slug' => 'rosas-eternas']);
        $this->assertDatabaseHas('products', ['tenant_id' => $tenant->id, 'name' => 'Rosa Eterna Clasica']);

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $this->assertCount(6, $products);

        // The product has variants, each with starting inventory at the main branch.
        $product = $products->firstWhere('name', 'Rosa Eterna Clasica');
        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->get();
        $this->assertGreaterThan(0, $variants->count());

        $this->assertDatabaseHas('branch_inventory', [
            'branch_id' => $branch->id,
            'product_variant_id' => $variants->first()->id,
            'quantity' => 20,
        ]);
    }

    public function test_provision_without_business_type_defaults_to_otro_and_seeds_no_catalog(): void
    {
        $this->basico();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload())
            ->assertStatus(201);

        $tenant = Tenant::withoutGlobalScopes()->where('slug', 'mi-tienda')->firstOrFail();
        // 'otro' has no starter_template in the catalog -> nothing is seeded.
        $this->assertSame('otro', $tenant->business_type);
        $this->assertSame(0, Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_provision_rejects_an_invalid_business_type(): void
    {
        $this->basico();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tenants', $this->validPayload(['business_type' => 'casino']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('business_type');
    }

    public function test_starter_catalog_is_isolated_to_the_new_tenant(): void
    {
        $this->basico();

        $userA = User::factory()->create();
        $this->actingAs($userA)
            ->postJson('/api/v1/tenants', $this->validPayload(['slug' => 'tienda-a', 'business_type' => 'peluches']))
            ->assertStatus(201);

        $userB = User::factory()->create();
        $this->actingAs($userB)
            ->postJson('/api/v1/tenants', $this->validPayload(['slug' => 'tienda-b', 'business_type' => 'accesorios']))
            ->assertStatus(201);

        $tenantA = Tenant::withoutGlobalScopes()->where('slug', 'tienda-a')->firstOrFail();
        $tenantB = Tenant::withoutGlobalScopes()->where('slug', 'tienda-b')->firstOrFail();

        $this->assertDatabaseHas('products', ['tenant_id' => $tenantA->id, 'name' => 'Oso de Peluche Clasico']);
        $this->assertDatabaseMissing('products', ['tenant_id' => $tenantA->id, 'name' => 'Collar Minimalista Plata']);
        $this->assertDatabaseHas('products', ['tenant_id' => $tenantB->id, 'name' => 'Collar Minimalista Plata']);
        $this->assertDatabaseMissing('products', ['tenant_id' => $tenantB->id, 'name' => 'Oso de Peluche Clasico']);
    }
}
