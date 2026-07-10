<?php

declare(strict_types=1);

namespace Database\Factories\POS;

use App\Models\User;
use App\Modules\POS\Models\CashRegisterSession;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegisterSession>
 *
 * Usage:
 *   CashRegisterSession::factory()->forBranch($branch)->forCashier($user)->create()
 *
 * forBranch() and forCashier() must both be called — a session cannot exist without a
 * branch and a cashier.
 */
class CashRegisterSessionFactory extends Factory
{
    protected $model = CashRegisterSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'user_id' => null,
            'session_number' => $this->faker->unique()->numberBetween(1, 100000),
            'opening_amount_cents' => 10000,
            'status' => 'open',
            'opened_at' => now(),
            'opening_notes' => null,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function forCashier(User $cashier): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $cashier->id,
        ]);
    }
}
