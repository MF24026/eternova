<?php

declare(strict_types=1);

namespace Database\Factories\POS;

use App\Models\User;
use App\Modules\POS\Models\CashMovement;
use App\Modules\POS\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_register_session_id' => null,
            'user_id' => null,
            'type' => 'out',
            'amount_cents' => 1000,
            'reason' => $this->faker->words(3, true),
        ];
    }

    public function forSession(CashRegisterSession $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $session->tenant_id,
            'cash_register_session_id' => $session->id,
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->id,
        ]);
    }
}
