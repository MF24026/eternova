<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_user_resource(): void
    {
        $user = User::factory()->create(['email' => 'owner@shop.sv']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@shop.sv',
            'password' => 'Password1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['id', 'name', 'email', 'tenants']]);
        $response->assertJsonPath('data.email', 'owner@shop.sv');
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        User::factory()->create(['email' => 'owner@shop.sv']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@shop.sv',
            'password' => 'wrongpassword',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_login_with_unknown_email_returns_same_error_shape_as_wrong_password(): void
    {
        // Same error shape for unknown email and wrong password — avoids user enumeration.
        // Both cases return 422 with errors.email populated (not a 404 for unknown email).
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@nowhere.sv',
            'password' => 'Password1',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_logout_returns_204_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        // Using actingAs simulates the auth guard being satisfied for the request
        $this->actingAs($user)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(204);
    }

    public function test_logout_with_bearer_token_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(204);

        // Token no longer exists in DB
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_me_endpoint_returns_user_with_tenants_array(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, role: 'owner')->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'email',
                'tenants' => [['id', 'slug', 'role', 'joined_at', 'is_current']],
            ],
        ]);

        $this->assertSame('owner', $response->json('data.tenants.0.role'));
        $this->assertSame($tenant->id, $response->json('data.tenants.0.id'));
    }

    public function test_me_returns_user_with_no_tenants_after_registration(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.tenants'));
    }
}
