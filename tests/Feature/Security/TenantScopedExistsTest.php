<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The tenant_exists() / tenant_exists_variant() validation helpers must reject an
 * id that belongs to a DIFFERENT tenant. Plain `exists:table,id` queries the
 * table directly and bypasses the BelongsToTenant scope, so a user in tenant A
 * could reference tenant B's category/customer/variant by id. These tests pin
 * the scoping shut, and confirm a tenant's OWN rows still validate.
 */
final class TenantScopedExistsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_exists_rejects_a_row_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $foreign = Category::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantA);

        $validator = Validator::make(
            ['category' => $foreign->id],
            ['category' => [tenant_exists('categories')]],
        );

        $this->assertTrue($validator->fails(), 'A category from tenant B must not validate under tenant A.');
    }

    public function test_tenant_exists_accepts_a_row_from_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $own = Category::factory()->forTenant($tenant)->create();

        $validator = Validator::make(
            ['category' => $own->id],
            ['category' => [tenant_exists('categories')]],
        );

        $this->assertFalse($validator->fails(), "A tenant's own category must still validate.");
    }

    public function test_tenant_exists_scopes_customers_too(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $foreignCustomer = Customer::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantA);

        $validator = Validator::make(
            ['customer_id' => $foreignCustomer->id],
            ['customer_id' => [tenant_exists('customers')]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_tenant_exists_variant_rejects_a_variant_owned_by_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        app()->instance('currentTenant', $tenantB);
        $foreignProduct = Product::factory()->forTenant($tenantB)->create();
        $foreignVariant = ProductVariant::factory()->forProduct($foreignProduct)->create();

        // Switch context to tenant A — B's variant must not validate.
        app()->instance('currentTenant', $tenantA);

        $validator = Validator::make(
            ['variant' => $foreignVariant->id],
            ['variant' => [tenant_exists_variant()]],
        );

        $this->assertTrue($validator->fails(), "Tenant B's product variant must not validate under tenant A.");
    }

    public function test_tenant_exists_variant_accepts_own_variant(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $product = Product::factory()->forTenant($tenant)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $validator = Validator::make(
            ['variant' => $variant->id],
            ['variant' => [tenant_exists_variant()]],
        );

        $this->assertFalse($validator->fails());
    }
}
