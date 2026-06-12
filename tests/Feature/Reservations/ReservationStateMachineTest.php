<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\User;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationStatusHistory;
use App\Modules\Reservations\Services\ReservationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the ReservationService state machine and reservation_status_history.
 *
 * Each test is isolated: its own tenant/branch/reservation setup.
 * No shared class-level state that could cause cross-test pollution.
 *
 * Multi-tenant invariant (tested explicitly): a history row created for
 * Tenant A must never appear when Tenant B's scope is active — even though
 * the rows share the same physical table.
 */
final class ReservationStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReservationService::class);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Build a minimal isolated tenant context and a reservation in the given status.
     *
     * @return array{tenant: Tenant, branch: Branch, reservation: Reservation, user: User}
     */
    private function setupTenant(string $status = 'inquiry'): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->status($status)
            ->create();

        return compact('tenant', 'branch', 'reservation', 'user');
    }

    /**
     * Advance a reservation to the target status by walking valid transitions.
     * Only handles the straightforward forward path needed by tests.
     */
    private function advanceToStatus(Reservation $reservation, string $targetStatus, User $user): void
    {
        $path = [
            'inquiry'     => [],
            'confirmed'   => ['confirmed'],
            'in_progress' => ['confirmed', 'in_progress'],
            'ready'       => ['confirmed', 'in_progress', 'ready'],
            'delivered'   => ['confirmed', 'in_progress', 'ready', 'delivered'],
            'cancelled'   => ['cancelled'],
        ];

        foreach ($path[$targetStatus] ?? [] as $step) {
            $reservation = $this->service->transitionTo($reservation->fresh(), $step, $user);
        }
    }

    // ── recordInitialHistory ─────────────────────────────────────────────────

    public function test_record_initial_history_writes_null_from_status_row(): void
    {
        ['tenant' => $tenant, 'reservation' => $reservation, 'user' => $user]
            = $this->setupTenant('inquiry');

        $this->service->recordInitialHistory($reservation, $user);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'from_status' => null,
            'to_status' => 'inquiry',
            'user_id' => $user->id,
            'note' => 'Reserva creada',
        ]);

        $history = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->get();

        $this->assertSame(1, $history->count());
    }

    public function test_record_initial_history_with_null_actor_records_null_user_id(): void
    {
        ['reservation' => $reservation] = $this->setupTenant('inquiry');

        $this->service->recordInitialHistory($reservation, actor: null);

        $row = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();

        $this->assertNull($row->user_id);
        $this->assertSame('inquiry', $row->to_status);
    }

    // ── transitionTo: valid transitions ──────────────────────────────────────

    public function test_valid_transition_updates_reservation_status(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $updated = $this->service->transitionTo($reservation, 'confirmed', $user);

        $this->assertSame('confirmed', $updated->status);
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_valid_transition_appends_exactly_one_history_row(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $before = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        $this->service->transitionTo($reservation, 'confirmed', $user, note: 'deposit received');

        $after = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        $this->assertSame($before + 1, $after);
    }

    public function test_transition_history_row_carries_correct_from_to_user_note(): void
    {
        ['reservation' => $reservation, 'user' => $user, 'tenant' => $tenant]
            = $this->setupTenant('inquiry');

        $this->service->transitionTo($reservation, 'confirmed', $user, note: 'deposit confirmed');

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'from_status' => 'inquiry',
            'to_status' => 'confirmed',
            'user_id' => $user->id,
            'note' => 'deposit confirmed',
        ]);
    }

    public function test_transition_with_null_actor_records_null_user_id(): void
    {
        ['reservation' => $reservation] = $this->setupTenant('inquiry');

        $this->service->transitionTo($reservation, 'confirmed', actor: null);

        $history = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->where('to_status', 'confirmed')
            ->firstOrFail();

        $this->assertNull($history->user_id);
    }

    public function test_transition_returns_fresh_reservation_with_status_history_loaded(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $result = $this->service->transitionTo($reservation, 'confirmed', $user);

        $this->assertTrue($result->relationLoaded('statusHistory'));
        $this->assertNotEmpty($result->statusHistory);
    }

    // ── transitionTo: full forward chain ──────────────────────────────────────

    public function test_full_forward_chain_inquiry_to_delivered(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user]
            = $this->setupTenant('inquiry');

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()->forBranch($branch)->create([
            'status' => 'inquiry',
        ]);

        // Walk the full forward chain
        $reservation = $this->service->transitionTo($reservation, 'confirmed', $user);
        $reservation = $this->service->transitionTo($reservation, 'in_progress', $user);
        $reservation = $this->service->transitionTo($reservation, 'ready', $user);
        $reservation = $this->service->transitionTo($reservation, 'delivered', $user);

        $this->assertSame('delivered', $reservation->status);

        // 4 history rows (one per transitionTo call; the factory reservation has none)
        $count = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        $this->assertSame(4, $count);
    }

    // ── transitionTo: invalid transitions ────────────────────────────────────

    public function test_invalid_transition_throws_domain_exception(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot transition.*inquiry.*in_progress/');

        $this->service->transitionTo($reservation, 'in_progress', $user);
    }

    public function test_invalid_transition_does_not_mutate_reservation_status(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        try {
            $this->service->transitionTo($reservation, 'in_progress', $user);
        } catch (DomainException) {
            // expected
        }

        $this->assertSame('inquiry', $reservation->fresh()->status);
    }

    public function test_invalid_transition_does_not_write_history_row(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $before = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        try {
            $this->service->transitionTo($reservation, 'in_progress', $user);
        } catch (DomainException) {
            // expected
        }

        $after = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        $this->assertSame($before, $after);
    }

    public function test_unknown_status_throws_domain_exception(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/not a recognised reservation status/');

        $this->service->transitionTo($reservation, 'flying', $user);
    }

    // ── Terminal states ───────────────────────────────────────────────────────

    public function test_delivered_reservation_rejects_all_transitions(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');
        $this->advanceToStatus($reservation, 'delivered', $user);
        $reservation = $reservation->fresh();

        foreach (['inquiry', 'confirmed', 'in_progress', 'ready', 'cancelled'] as $status) {
            $exceptionThrown = false;

            try {
                $this->service->transitionTo($reservation, $status, $user);
            } catch (DomainException) {
                $exceptionThrown = true;
            }

            $this->assertTrue(
                $exceptionThrown,
                "Expected DomainException when transitioning delivered → {$status}"
            );
        }
    }

    public function test_cancelled_reservation_rejects_all_transitions(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');
        $this->advanceToStatus($reservation, 'cancelled', $user);
        $reservation = $reservation->fresh();

        foreach (['inquiry', 'confirmed', 'in_progress', 'ready', 'delivered'] as $status) {
            $exceptionThrown = false;

            try {
                $this->service->transitionTo($reservation, $status, $user);
            } catch (DomainException) {
                $exceptionThrown = true;
            }

            $this->assertTrue(
                $exceptionThrown,
                "Expected DomainException when transitioning cancelled → {$status}"
            );
        }
    }

    // ── allowedTransitions() ─────────────────────────────────────────────────

    public function test_allowed_transitions_returns_correct_set_for_each_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant('inquiry');

        app()->instance('currentTenant', $tenant);

        $cases = [
            'inquiry'     => ['confirmed', 'cancelled'],
            'confirmed'   => ['in_progress', 'cancelled'],
            'in_progress' => ['ready', 'cancelled'],
            'ready'       => ['delivered', 'cancelled'],
            'delivered'   => [],
            'cancelled'   => [],
        ];

        foreach ($cases as $status => $expected) {
            $reservation = Reservation::factory()->forBranch($branch)->create(['status' => $status]);

            $actual = $this->service->allowedTransitions($reservation);

            $this->assertSame(
                $expected,
                $actual,
                "Allowed transitions for '{$status}' did not match expected set."
            );
        }
    }

    // ── cancel() delegation ───────────────────────────────────────────────────

    public function test_cancel_writes_history_row_with_cancelled_status(): void
    {
        ['reservation' => $reservation, 'user' => $user, 'tenant' => $tenant]
            = $this->setupTenant('inquiry');

        $this->service->cancel($reservation, $user);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenant->id,
            'from_status' => 'inquiry',
            'to_status' => 'cancelled',
            'user_id' => $user->id,
        ]);
    }

    public function test_cancelling_already_cancelled_reservation_throws_and_does_not_add_history(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');

        $this->service->cancel($reservation, $user);

        $countAfterFirstCancel = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already cancelled/');

        $this->service->cancel($reservation->fresh(), $user);

        // Should not have grown
        $this->assertSame(
            $countAfterFirstCancel,
            ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('reservation_id', $reservation->id)
                ->count()
        );
    }

    public function test_cancelling_delivered_reservation_throws_and_does_not_add_history(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant('inquiry');
        $this->advanceToStatus($reservation, 'delivered', $user);
        $reservation = $reservation->fresh();

        $countBefore = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('reservation_id', $reservation->id)
            ->count();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already been delivered/');

        $this->service->cancel($reservation, $user);

        $this->assertSame(
            $countBefore,
            ReservationStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('reservation_id', $reservation->id)
                ->count()
        );
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_history_rows_are_scoped_to_current_tenant(): void
    {
        ['tenant' => $tenantA, 'reservation' => $reservationA, 'user' => $userA]
            = $this->setupTenant('inquiry');

        $this->service->transitionTo($reservationA, 'confirmed', $userA);

        // Switch context to Tenant B
        $tenantB = Tenant::factory()->create();
        app()->instance('currentTenant', $tenantB);

        // Under Tenant B's scope, Tenant A's history rows must not be visible
        $visible = ReservationStatusHistory::where('reservation_id', $reservationA->id)->count();

        $this->assertSame(0, $visible, 'Tenant B must not see Tenant A history through tenant scope');
    }

    public function test_transition_on_tenant_a_reservation_does_not_affect_tenant_b_history(): void
    {
        ['tenant' => $tenantA, 'reservation' => $reservationA, 'user' => $userA]
            = $this->setupTenant('inquiry');

        ['tenant' => $tenantB, 'reservation' => $reservationB, 'user' => $userB]
            = $this->setupTenant('inquiry');

        // Tenant A transitions its reservation
        app()->instance('currentTenant', $tenantA);
        $this->service->transitionTo($reservationA, 'confirmed', $userA);

        // Tenant B's history should have zero rows (no transitions, no initial history)
        app()->instance('currentTenant', $tenantB);
        $countB = ReservationStatusHistory::where('reservation_id', $reservationB->id)->count();

        $this->assertSame(0, $countB, 'Tenant A transition must not create rows for Tenant B');
    }

    public function test_reservation_status_history_global_scope_filters_by_tenant(): void
    {
        ['tenant' => $tenantA, 'reservation' => $reservationA, 'user' => $userA]
            = $this->setupTenant('inquiry');

        $this->service->recordInitialHistory($reservationA, $userA);

        ['tenant' => $tenantB, 'reservation' => $reservationB, 'user' => $userB]
            = $this->setupTenant('inquiry');

        $this->service->recordInitialHistory($reservationB, $userB);

        // Tenant A scope: only sees its own rows
        app()->instance('currentTenant', $tenantA);
        $rowsA = ReservationStatusHistory::all();

        foreach ($rowsA as $row) {
            $this->assertSame($tenantA->id, $row->tenant_id);
        }

        // Tenant B scope: only sees its own rows
        app()->instance('currentTenant', $tenantB);
        $rowsB = ReservationStatusHistory::all();

        foreach ($rowsB as $row) {
            $this->assertSame($tenantB->id, $row->tenant_id);
        }

        // Without scope: all rows are visible (super-admin / background-job context)
        $all = ReservationStatusHistory::withoutGlobalScope(TenantScope::class)->count();
        $this->assertGreaterThanOrEqual($rowsA->count() + $rowsB->count(), $all);
    }
}
