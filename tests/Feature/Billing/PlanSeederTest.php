<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Plans\Models\Plan;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the PlansSeeder.
 *
 * Verifies that the three canonical plans are inserted with the correct data.
 * No mocks — runs against the real DB with RefreshDatabase.
 */
final class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlansSeeder::class);
    }

    public function test_seeder_inserts_three_plans(): void
    {
        $this->assertDatabaseCount('plans', 3);
    }

    public function test_seeder_inserts_all_expected_slugs(): void
    {
        $slugs = Plan::pluck('slug')->sort()->values()->all();

        $this->assertSame(['basico', 'enterprise', 'pro'], $slugs);
    }

    public function test_basico_plan_has_correct_pricing(): void
    {
        $plan = Plan::where('slug', 'basico')->firstOrFail();

        $this->assertSame(900, $plan->price_monthly_cents);
        $this->assertSame(9000, $plan->price_yearly_cents);
        $this->assertSame('USD', $plan->currency);
    }

    public function test_basico_plan_has_correct_limits(): void
    {
        $plan = Plan::where('slug', 'basico')->firstOrFail();

        $this->assertSame(1, $plan->limits['max_branches']);
        $this->assertSame(50, $plan->limits['max_products']);
        $this->assertSame(3, $plan->limits['max_users']);
        $this->assertSame(200, $plan->limits['max_orders_per_month']);
        $this->assertFalse($plan->limits['pdf_quotations']);
        $this->assertSame(0, $plan->limits['ocr_receipts_per_month']);
        $this->assertFalse($plan->limits['custom_domain']);
    }

    public function test_pro_plan_has_correct_pricing(): void
    {
        $plan = Plan::where('slug', 'pro')->firstOrFail();

        $this->assertSame(2900, $plan->price_monthly_cents);
        $this->assertSame(29000, $plan->price_yearly_cents);
    }

    public function test_pro_plan_has_correct_limits(): void
    {
        $plan = Plan::where('slug', 'pro')->firstOrFail();

        $this->assertSame(3, $plan->limits['max_branches']);
        $this->assertSame(1000, $plan->limits['max_products']);
        $this->assertSame(10, $plan->limits['max_users']);
        $this->assertSame(5000, $plan->limits['max_orders_per_month']);
        $this->assertTrue($plan->limits['pdf_quotations']);
        $this->assertSame(50, $plan->limits['ocr_receipts_per_month']);
        $this->assertFalse($plan->limits['custom_domain']);
    }

    public function test_enterprise_plan_has_unlimited_limits_marked_as_null(): void
    {
        $plan = Plan::where('slug', 'enterprise')->firstOrFail();

        $this->assertNull($plan->limits['max_branches']);
        $this->assertNull($plan->limits['max_products']);
        $this->assertNull($plan->limits['max_users']);
        $this->assertNull($plan->limits['max_orders_per_month']);
        $this->assertNull($plan->limits['ocr_receipts_per_month']);

        // Non-null enterprise-only features
        $this->assertTrue($plan->limits['pdf_quotations']);
        $this->assertTrue($plan->limits['custom_domain']);
    }

    public function test_enterprise_plan_has_correct_pricing(): void
    {
        $plan = Plan::where('slug', 'enterprise')->firstOrFail();

        $this->assertSame(9900, $plan->price_monthly_cents);
        $this->assertSame(99000, $plan->price_yearly_cents);
    }

    public function test_all_plans_are_active(): void
    {
        $inactivePlans = Plan::where('is_active', false)->count();

        $this->assertSame(0, $inactivePlans);
    }

    public function test_plans_are_sorted_correctly(): void
    {
        $plans = Plan::orderBy('sort_order')->pluck('slug')->all();

        $this->assertSame(['basico', 'pro', 'enterprise'], $plans);
    }

    public function test_enterprise_plan_yearly_savings_is_two_months_free(): void
    {
        $plan = Plan::where('slug', 'enterprise')->firstOrFail();

        $twoMonthsValue = $plan->price_monthly_cents * 2;
        $actualSavings = $plan->yearlySavingsCents();

        $this->assertSame($twoMonthsValue, $actualSavings);
    }

    public function test_plan_getlimit_returns_null_for_unlimited_enterprise_key(): void
    {
        $plan = Plan::where('slug', 'enterprise')->firstOrFail();

        $this->assertNull($plan->getLimit('max_branches'));
        $this->assertNull($plan->getLimit('max_products'));
    }

    public function test_plan_getlimit_returns_integer_for_basico_bounded_key(): void
    {
        $plan = Plan::where('slug', 'basico')->firstOrFail();

        $this->assertSame(1, $plan->getLimit('max_branches'));
        $this->assertSame(50, $plan->getLimit('max_products'));
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_plans(): void
    {
        // Run seeder a second time
        $this->seed(PlansSeeder::class);

        // Still exactly 3 plans — updateOrCreate prevents duplicates
        $this->assertDatabaseCount('plans', 3);
    }
}
