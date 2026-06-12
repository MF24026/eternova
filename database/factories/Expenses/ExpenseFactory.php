<?php

declare(strict_types=1);

namespace Database\Factories\Expenses;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 *
 * Usage patterns:
 *   Expense::factory()->forTenant($tenant)->create()
 *   Expense::factory()->forTenant($tenant)->forBranch($branch)->forCategory($cat)->create()
 *   Expense::factory()->forTenant($tenant)->draft()->create()
 *   Expense::factory()->forTenant($tenant)->verified()->create()
 *
 * forTenant() should always be set — every expense belongs to a tenant
 * (the legacy schema's missing tenant_id was the bug this retrofit fixes).
 *
 * Note on DB defaults: factory create() does NOT hydrate column defaults onto
 * the in-memory model instance. Call ->fresh() before asserting defaulted columns
 * (e.g. ocr_status, is_verified) when those are not set explicitly in the factory.
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id'           => null,
            'expense_category_id' => null,
            'description'         => $this->faker->sentence(4),
            'amount_cents'        => $this->faker->numberBetween(1000, 100000),
            'expense_date'        => $this->faker->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'vendor'              => $this->faker->optional(0.7)->company(),
            'payment_method'      => $this->faker->randomElement(['cash', 'card', 'transfer', 'other']),
            'receipt_path'        => null,
            'ocr_status'          => 'none',
            'ocr_data'            => null,
            // Manual entry default: staff-confirmed at creation (no OCR involved).
            'is_verified'         => true,
            'notes'               => $this->faker->optional(0.3)->sentence(),
            'created_by'          => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function forCategory(ExpenseCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_category_id' => $category->id,
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    /**
     * A staff-confirmed expense (the normal manual-entry state).
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'ocr_status'  => 'none',
        ]);
    }

    /**
     * An unverified draft — simulates a receipt upload waiting for OCR review.
     *
     * ocr_status=done and a fake receipt_path are included to represent the most
     * common draft state (OCR finished, staff hasn't confirmed yet). Use
     * ->state(['ocr_status' => 'pending']) to simulate a freshly queued upload.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified'  => false,
            'ocr_status'   => 'done',
            'receipt_path' => 'expenses/receipts/'.fake()->uuid().'.jpg',
            'ocr_data'     => [
                'vendor'       => fake()->company(),
                'amount_cents' => fake()->numberBetween(1000, 50000),
                'date'         => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
                'raw_text'     => 'FACTURA 001 TOTAL $'.number_format(fake()->numberBetween(10, 500), 2),
                'confidence'   => fake()->randomFloat(2, 0.50, 0.99),
            ],
        ]);
    }

    /**
     * A failed OCR expense — Tesseract exhausted its retries.
     *
     * The expense remains a draft (is_verified=false) with a receipt_path present
     * but ocr_data empty; staff must fill in the fields manually.
     */
    public function ocrFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified'  => false,
            'ocr_status'   => 'failed',
            'receipt_path' => 'expenses/receipts/'.fake()->uuid().'.jpg',
            'ocr_data'     => null,
        ]);
    }
}
