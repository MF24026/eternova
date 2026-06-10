<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class BranchApiTest extends TestCase
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

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a-branch']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b-branch']);
        $this->ownerA = User::factory()->forTenant($this->tenantA, role: 'owner')->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson($this->tenantUrl($this->tenantA, '/api/v1/branches'))
            ->assertStatus(401);
    }

    public function test_owner_can_list_branches(): void
    {
        Branch::factory()->forTenant($this->tenantA)->main()->create(['name' => 'Sucursal Principal']);
        Branch::factory()->forTenant($this->tenantA)->create(['name' => 'Sucursal Norte']);

        $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/branches')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'is_main', 'is_active']],
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_list_only_returns_branches_for_current_tenant(): void
    {
        Branch::factory()->forTenant($this->tenantA)->create();
        Branch::factory()->forTenant($this->tenantB)->count(3)->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/branches')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_main_branch_is_listed_first(): void
    {
        Branch::factory()->forTenant($this->tenantA)->create(['name' => 'Aaa Secondary']);
        $main = Branch::factory()->forTenant($this->tenantA)->main()->create(['name' => 'Zzz Main']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/branches')
            ->assertOk();

        $this->assertSame($main->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_main'));
    }

    public function test_inactive_branches_are_excluded_by_default(): void
    {
        Branch::factory()->forTenant($this->tenantA)->create(['name' => 'Active']);
        Branch::factory()->forTenant($this->tenantA)->inactive()->create(['name' => 'Closed']);

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/branches')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Active', $response->json('data.0.name'));
    }

    public function test_inactive_branches_can_be_included_with_flag(): void
    {
        Branch::factory()->forTenant($this->tenantA)->create();
        Branch::factory()->forTenant($this->tenantA)->inactive()->create();

        $response = $this->tenantGetJson($this->tenantA, $this->ownerA, '/api/v1/branches?active_only=0')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_staff_can_list_branches(): void
    {
        $staff = User::factory()->forTenant($this->tenantA, role: 'staff')->create();
        Branch::factory()->forTenant($this->tenantA)->create();

        $this->tenantGetJson($this->tenantA, $staff, '/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
