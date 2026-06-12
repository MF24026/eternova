<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Http\Requests\UpdateReservationSettingsRequest;
use App\Modules\Reservations\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-level reservation configuration.
 *
 * Exposes and mutates two settings that tenants can personalise:
 *   - deposit_pct: default deposit percentage (0–100) applied to new reservations
 *   - occasions: list of occasion labels shown in the reservation capture form
 *
 * These settings live on the tenants table (reservation_deposit_pct /
 * reservation_occasions) rather than the generic settings table because they
 * were introduced before the Settings module was ready (Sprint 7). When the
 * Settings module matures, these may migrate there — the API surface stays the same.
 *
 * Only owner / admin roles may write settings. Staff (read-only) and customers
 * are denied by the ReservationPolicy::manageSettings() gate.
 */
final class ReservationSettingsController extends Controller
{
    /**
     * The default occasion labels used when the tenant has not configured their own list.
     *
     * @var list<string>
     */
    private const DEFAULT_OCCASIONS = [
        'Boda',
        'Cumpleanos',
        'Aniversario',
        'Corporativo',
        'Quinceanera',
        'Otro',
    ];

    /**
     * Return the current tenant's reservation settings.
     *
     * Read access is available to all tenant staff so they can see the occasion
     * list when capturing reservations. Write is restricted — see update().
     */
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', Reservation::class);

        $tenant = current_tenant();

        return response()->json([
            'data' => [
                'deposit_pct' => $tenant?->reservation_deposit_pct ?? 30,
                'occasions'   => $tenant?->reservation_occasions ?? self::DEFAULT_OCCASIONS,
            ],
        ]);
    }

    /**
     * Update the current tenant's reservation settings.
     *
     * Restricted to owner and admin roles — see ReservationPolicy::manageSettings().
     */
    public function update(UpdateReservationSettingsRequest $request): JsonResponse
    {
        $this->authorize('manageSettings', Reservation::class);

        $data = $request->validated();

        $tenant = current_tenant();

        $tenant->update([
            'reservation_deposit_pct'  => (int) $data['deposit_pct'],
            'reservation_occasions'    => $data['occasions'] ?? null,
        ]);

        Log::info('Reservation settings updated', [
            'tenant_id'   => $tenant->id,
            'deposit_pct' => $data['deposit_pct'],
            'actor_id'    => $request->user()?->id,
        ]);

        $tenant->refresh();

        return response()->json([
            'data' => [
                'deposit_pct' => $tenant->reservation_deposit_pct,
                'occasions'   => $tenant->reservation_occasions ?? self::DEFAULT_OCCASIONS,
            ],
        ]);
    }
}
