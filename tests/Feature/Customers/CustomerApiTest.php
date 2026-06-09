<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class CustomerApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenantA = Tenant::factory()->create([
            'slug' => 'tenant-a-cust',
            'country_code' => 'SV',
        ]);
        $this->tenantB = Tenant::factory()->create([
            'slug' => 'tenant-b-cust',
            'country_code' => 'SV',
        ]);
        $this->ownerA = User::factory()->forTenant($this->tenantA, role: 'owner')->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson($this->tenantUrl($this->tenantA, '/api/v1/customers'))
            ->assertStatus(401);
    }

    public function test_owner_can_list_customers(): void
    {
        Customer::factory()->forTenant($this->tenantA)->count(3)->create();

        $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/customers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'phone', 'whatsapp']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
            ]);
    }

    public function test_list_only_returns_customers_for_current_tenant(): void
    {
        Customer::factory()->forTenant($this->tenantA)->count(2)->create();
        Customer::factory()->forTenant($this->tenantB)->count(5)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/customers')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_supports_search_by_name(): void
    {
        Customer::factory()->forTenant($this->tenantA)->create(['name' => 'Maria Lopez']);
        Customer::factory()->forTenant($this->tenantA)->create(['name' => 'Carlos Garcia']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/customers?search=maria')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Maria Lopez', $response->json('data.0.name'));
    }

    public function test_list_supports_search_by_phone(): void
    {
        Customer::factory()->forTenant($this->tenantA)->withPhone('76541111')->create(['name' => 'Ana']);
        Customer::factory()->forTenant($this->tenantA)->withPhone('76542222')->create(['name' => 'Luis']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/customers?search=76541111')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ana', $response->json('data.0.name'));
    }

    public function test_user_of_tenant_a_gets_404_on_tenant_b_customer(): void
    {
        $customerB = Customer::factory()->forTenant($this->tenantB)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/customers/{$customerB->id}");

        // Either 403 (policy blocks it) or 404 (BelongsToTenant scope hides it)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_owner_can_create_customer(): void
    {
        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'name' => 'Sofia Ramirez',
            'email' => 'sofia@example.com',
            'phone' => '76541234',
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame('Sofia Ramirez', $data['name']);
        $this->assertSame('sofia@example.com', $data['email']);
        $this->assertSame('76541234', $data['phone']);

        $this->assertDatabaseHas('customers', [
            'name' => 'Sofia Ramirez',
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    public function test_owner_can_show_customer(): void
    {
        $customer = Customer::factory()->forTenant($this->tenantA)->create(['name' => 'Pedro Test']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, "/api/v1/customers/{$customer->id}")
            ->assertOk();

        $this->assertSame($customer->id, $response->json('data.id'));
        $this->assertSame('Pedro Test', $response->json('data.name'));
    }

    public function test_owner_can_update_customer(): void
    {
        $customer = Customer::factory()->forTenant($this->tenantA)->create(['name' => 'Old Name']);

        $response = $this->tenantPatchJson($this->tenantA, $this->ownerA, "/api/v1/customers/{$customer->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])->assertOk();

        $this->assertSame('New Name', $response->json('data.name'));
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'New Name']);
    }

    public function test_owner_can_soft_delete_customer(): void
    {
        $customer = Customer::factory()->forTenant($this->tenantA)->create();

        $this->tenantDeleteJson($this->tenantA, $this->ownerA, "/api/v1/customers/{$customer->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_owner_can_restore_soft_deleted_customer(): void
    {
        $customer = Customer::factory()->forTenant($this->tenantA)->create();
        $customer->delete();

        $response = $this->tenantPostJson($this->tenantA, $this->ownerA, "/api/v1/customers/{$customer->id}/restore")
            ->assertOk();

        $this->assertSame($customer->id, $response->json('data.id'));
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_staff_can_create_customer(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();

        $this->tenantPostJson($this->tenantA, $staff, '/api/v1/customers', [
            'name' => 'Walk-in Customer',
        ])->assertStatus(201);
    }

    public function test_staff_cannot_delete_customer(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();
        $customer = Customer::factory()->forTenant($this->tenantA)->create();

        $this->tenantDeleteJson($this->tenantA, $staff, "/api/v1/customers/{$customer->id}")
            ->assertStatus(403);
    }

    public function test_phone_validation_rejects_wrong_length_for_country(): void
    {
        // tenantA has country_code=SV which expects 8 digits; 10 digits must fail
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'name' => 'Test Customer',
            'phone' => '3001234567', // 10 digits — wrong for SV
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('phone');
    }

    public function test_phone_validation_accepts_correct_length_for_country(): void
    {
        // SV = 8 digits
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'name' => 'Test Customer',
            'phone' => '76541234', // 8 digits — correct for SV
        ])->assertStatus(201);
    }

    public function test_phone_is_optional(): void
    {
        // Creating a customer without phone should succeed
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'name' => 'No Phone Customer',
        ])->assertStatus(201);
    }

    public function test_phone_formatted_with_separator_passes_validation(): void
    {
        // "7654-1234" has 8 digits after stripping non-digits — valid for SV
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'name' => 'Formatted Phone',
            'phone' => '7654-1234',
        ])->assertStatus(201);
    }

    public function test_create_rejects_missing_name(): void
    {
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'email' => 'no-name@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('name');
    }

    public function test_create_rejects_invalid_email(): void
    {
        $this->tenantPostJson($this->tenantA, $this->ownerA, '/api/v1/customers', [
            'name' => 'Test',
            'email' => 'not-an-email',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }
}
