<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_to_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@shop.sv']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@shop.sv'])
            ->assertOk()
            ->assertJsonPath('message', 'Si el correo esta registrado, enviamos un enlace para restablecer la contrasena.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_for_unknown_email_returns_same_message_and_sends_nothing(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@shop.sv'])
            ->assertOk()
            ->assertJsonPath('message', 'Si el correo esta registrado, enviamos un enlace para restablecer la contrasena.');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_reset_password_with_valid_token_updates_the_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@shop.sv']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'owner@shop.sv',
            'password'              => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassw0rd!', $user->password));

        // The new password works on login.
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'owner@shop.sv',
            'password' => 'NewPassw0rd!',
        ])->assertStatus(200);
    }

    public function test_reset_password_with_invalid_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'owner@shop.sv']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => 'totally-invalid-token',
            'email'                 => 'owner@shop.sv',
            'password'              => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_reset_password_requires_confirmation_and_minimum_length(): void
    {
        $user = User::factory()->create(['email' => 'owner@shop.sv']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token'                 => $token,
            'email'                 => 'owner@shop.sv',
            'password'              => 'short',
            'password_confirmation' => 'mismatch',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('password');
    }
}
