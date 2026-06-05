<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RegisterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Flores',
            'email' => 'ana@floristeria.sv',
            'password' => 'Password1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'email', 'tenants'],
            'plain_text_token',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'ana@floristeria.sv']);

        // plain_text_token must be a non-empty string
        $this->assertNotEmpty($response->json('plain_text_token'));
    }

    public function test_register_does_not_create_tenant(): void
    {
        $tenantsBeforeCount = Tenant::withoutGlobalScopes()->count();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Carlos Lopez',
            'email' => 'carlos@shop.sv',
            'password' => 'Password1',
        ])->assertStatus(201);

        // A fresh registration must NOT create any tenant rows
        $this->assertSame($tenantsBeforeCount, Tenant::withoutGlobalScopes()->count());
    }

    public function test_register_returns_user_with_empty_tenants_array(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Maria Garcia',
            'email' => 'maria@shop.sv',
            'password' => 'Password1',
        ])->assertStatus(201);

        // Fresh user has zero tenants — the SPA proceeds to onboarding next
        $this->assertSame([], $response->json('data.tenants'));
    }

    public function test_register_rejects_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'weak@test.com',
            'password' => 'pass',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_register_rejects_password_without_uppercase(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'weak2@test.com',
            'password' => 'password1',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_register_rejects_password_without_number(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'weak3@test.com',
            'password' => 'Password',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@test.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Another User',
            'email' => 'existing@test.com',
            'password' => 'Password1',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_register_rejects_missing_name(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'noname@test.com',
            'password' => 'Password1',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('name');
    }
}
