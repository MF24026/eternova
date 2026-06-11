<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Team listing endpoint (GET /api/v1/team).
 *
 * Consumed by staff-assignment selectors (e.g. the order assignee picker). The
 * endpoint must only ever expose the CURRENT tenant's members.
 */
final class TeamApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/team'))
            ->assertStatus(401);
    }

    public function test_lists_current_tenant_members_with_role(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create(['name' => 'Ana Owner']);
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create(['name' => 'Beto Staff']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/team')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['id' => $owner->id, 'name' => 'Ana Owner', 'role' => 'owner']);
        $response->assertJsonFragment(['id' => $staff->id, 'name' => 'Beto Staff', 'role' => 'staff']);
    }

    public function test_does_not_leak_members_of_other_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $ownerA = User::factory()->forTenant($tenantA, role: 'owner')->create();

        $tenantB = Tenant::factory()->create();
        $ownerB = User::factory()->forTenant($tenantB, role: 'owner')->create(['name' => 'Other Tenant User']);

        $response = $this->tenantGetJson($tenantA, $ownerA, '/api/v1/team')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonMissing(['id' => $ownerB->id]);
    }
}
