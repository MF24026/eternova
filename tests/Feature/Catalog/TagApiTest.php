<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class TagApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenant = Tenant::factory()->create(['slug' => 'tag-api-tenant']);
        $this->owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson($this->tenantUrl($this->tenant, '/api/v1/tags'))
            ->assertStatus(401);
    }

    public function test_owner_can_list_tags(): void
    {
        Tag::factory()->forTenant($this->tenant)->count(3)->create();

        $this->tenantGetJson($this->tenant, $this->owner, '/api/v1/tags')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug']],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_returns_only_current_tenant_tags(): void
    {
        $tenantB = Tenant::factory()->create(['slug' => 'tag-tenant-b']);

        Tag::factory()->forTenant($this->tenant)->count(2)->create();
        Tag::factory()->forTenant($tenantB)->count(5)->create();

        $response = $this->tenantGetJson($this->tenant, $this->owner, '/api/v1/tags')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_owner_can_create_tag(): void
    {
        $response = $this->tenantPostJson($this->tenant, $this->owner, '/api/v1/tags', [
            'name' => 'Nuevo',
        ])->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame('Nuevo', $data['name']);
        $this->assertSame('nuevo', $data['slug']);

        $this->assertDatabaseHas('tags', [
            'name' => 'Nuevo',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_owner_can_update_tag(): void
    {
        $tag = Tag::factory()->forTenant($this->tenant)->create(['name' => 'oferta', 'slug' => 'oferta-tag']);

        $response = $this->tenantPatchJson($this->tenant, $this->owner, "/api/v1/tags/{$tag->id}", [
            'name' => 'Oferta Actualizada',
        ])->assertOk();

        $this->assertSame('Oferta Actualizada', $response->json('data.name'));
    }

    public function test_owner_can_delete_tag(): void
    {
        $tag = Tag::factory()->forTenant($this->tenant)->create(['name' => 'borrar', 'slug' => 'borrar-tag']);

        $this->tenantDeleteJson($this->tenant, $this->owner, "/api/v1/tags/{$tag->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_staff_cannot_create_tag(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();

        $this->tenantPostJson($this->tenant, $staff, '/api/v1/tags', [
            'name' => 'Staff Tag',
        ])->assertStatus(403);
    }
}
