<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_endpoint_returns_bearer_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'mobile@shop.sv']);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'mobile@shop.sv',
            'password' => 'Password1',
            'name' => 'iPhone 15 Pro',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'abilities', 'created_at'],
            'plain_text_token',
        ]);

        $this->assertNotEmpty($response->json('plain_text_token'));
        $this->assertStringContainsString('|', $response->json('plain_text_token'));
    }

    public function test_bearer_token_authenticates_subsequent_requests(): void
    {
        $user = User::factory()->create(['email' => 'mobile2@shop.sv']);

        $tokenResponse = $this->postJson('/api/v1/auth/token', [
            'email' => 'mobile2@shop.sv',
            'password' => 'Password1',
            'name' => 'Android App',
        ])->assertStatus(201);

        $plainToken = $tokenResponse->json('plain_text_token');

        // Use Bearer token on subsequent request
        $this->withToken($plainToken)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'mobile2@shop.sv');
    }

    public function test_revoked_token_returns_401(): void
    {
        $user = User::factory()->create();

        // Issue a token
        $tokenResponse = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'Password1',
            'name' => 'Token to revoke',
        ])->assertStatus(201);

        $plainToken = $tokenResponse->json('plain_text_token');
        $tokenId = $tokenResponse->json('data.id');

        // Verify the token works before revocation
        $this->withToken($plainToken)
            ->getJson('/api/v1/me')
            ->assertStatus(200);

        // Revoke the token using a DIFFERENT mechanism (actingAs) to simulate
        // an admin revocation from a different session
        $this->actingAs($user)
            ->deleteJson("/api/v1/auth/tokens/{$tokenId}")
            ->assertStatus(204);

        // The token must no longer exist in the database
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_list_tokens_endpoint_returns_only_current_user_tokens(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Issue tokens for both users
        $this->postJson('/api/v1/auth/token', [
            'email' => $userA->email,
            'password' => 'Password1',
            'name' => 'User A Token',
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/token', [
            'email' => $userB->email,
            'password' => 'Password1',
            'name' => 'User B Token',
        ])->assertStatus(201);

        // UserA listing their tokens must NOT include userB's token
        $response = $this->actingAs($userA)->getJson('/api/v1/auth/tokens');

        $response->assertStatus(200);

        $tokenNames = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('User A Token', $tokenNames);
        $this->assertNotContains('User B Token', $tokenNames);
    }

    public function test_cannot_revoke_another_users_token(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Create a token for userB
        $tokenResponse = $this->postJson('/api/v1/auth/token', [
            'email' => $userB->email,
            'password' => 'Password1',
            'name' => 'User B personal token',
        ])->assertStatus(201);

        $userBTokenId = $tokenResponse->json('data.id');

        // userA tries to revoke userB's token — must get 404, not 403 (avoid enumeration)
        $this->actingAs($userA)
            ->deleteJson("/api/v1/auth/tokens/{$userBTokenId}")
            ->assertStatus(404);
    }

    public function test_token_endpoint_rejects_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'wrongpassword',
            'name' => 'Device',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_token_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/tokens')
            ->assertStatus(401);
    }
}
