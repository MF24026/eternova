<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationPayment;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schema + model retrofit tests for Reservations (S5-E1).
 *
 * Verifies the retrofitted schema fixes the legacy bug (missing tenant_id), that
 * BelongsToTenant scoping works, relations resolve, and money is stored in centavos.
 */
final class ReservationSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    public function test_reservation_can_be_created_with_all_fields(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();
        $customer = Customer::factory()->forTenant($tenant)->create();

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->forCustomer($customer)
            ->create([
                'total_cents' => 50000,
                'deposit_required_cents' => 15000,
                'occasion' => 'Boda',
            ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'total_cents' => 50000,
            'deposit_required_cents' => 15000,
            'occasion' => 'Boda',
            'status' => 'inquiry',
        ]);
    }

    public function test_belongs_to_tenant_scope_filters_reservations(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        Reservation::factory()->forBranch($branchA)->count(2)->create();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        Reservation::factory()->forBranch($branchB)->create();

        // Current tenant is B → only B's reservation is visible.
        $this->assertSame(1, Reservation::count());

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(2, Reservation::count());
    }

    public function test_relations_resolve(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $customer = Customer::factory()->forTenant($tenant)->create();

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->forCustomer($customer)
            ->assignedTo($owner)
            ->create(['created_by' => $owner->id]);

        ReservationPayment::factory()->forReservation($reservation)->count(2)->create();

        $reservation->refresh();

        $this->assertCount(2, $reservation->payments);
        $this->assertTrue($reservation->customer->is($customer));
        $this->assertTrue($reservation->branch->is($branch));
        $this->assertTrue($reservation->assignee->is($owner));
        $this->assertTrue($reservation->creator->is($owner));
        $this->assertNull($reservation->convertedOrder);
    }

    public function test_converted_order_relation_resolves(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $order = Order::factory()->forBranch($branch)->create();
        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->create(['converted_order_id' => $order->id, 'status' => 'delivered']);

        $this->assertTrue($reservation->convertedOrder->is($order));
    }

    public function test_reservation_payments_are_tenant_scoped(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        $resA = Reservation::factory()->forBranch($branchA)->create();
        ReservationPayment::factory()->forReservation($resA)->create();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        $resB = Reservation::factory()->forBranch($branchB)->create();
        ReservationPayment::factory()->forReservation($resB)->create();

        // Current tenant is B → only B's payment is visible.
        $this->assertSame(1, ReservationPayment::count());
    }

    public function test_remaining_balance_is_total_minus_paid(): void
    {
        $this->setupTenant();

        $reservation = Reservation::factory()->create([
            'total_cents' => 50000,
            'deposit_paid_cents' => 15000,
        ]);

        $this->assertSame(35000, $reservation->remainingBalanceCents());
    }

    public function test_tenant_has_reservation_config_with_defaults(): void
    {
        // refresh() pulls the DB-applied default (factory create() does not hydrate
        // column defaults onto the in-memory model).
        $tenant = Tenant::factory()->create()->fresh();

        $this->assertSame(30, $tenant->reservation_deposit_pct);
        $this->assertNull($tenant->reservation_occasions);

        $tenant->update(['reservation_occasions' => ['Boda', 'Corporativo']]);
        $tenant->refresh();

        $this->assertSame(['Boda', 'Corporativo'], $tenant->reservation_occasions);
    }

    public function test_money_columns_are_integer_centavos(): void
    {
        $this->setupTenant();

        $reservation = Reservation::factory()->create(['total_cents' => 12345]);

        $this->assertIsInt($reservation->total_cents);
        $this->assertIsInt($reservation->deposit_required_cents);
        $this->assertIsInt($reservation->deposit_paid_cents);
    }
}
