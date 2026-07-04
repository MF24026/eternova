<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Reservations\Http\Requests\RecordPaymentRequest;
use App\Modules\Reservations\Http\Requests\StoreReservationRequest;
use App\Modules\Reservations\Http\Requests\TransitionReservationRequest;
use App\Modules\Reservations\Http\Resources\ReservationCollection;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Repositories\ReservationRepositoryInterface;
use App\Modules\Reservations\Services\ReservationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reservation management REST endpoints.
 *
 * Thin controller — every business rule lives in ReservationService.
 * This class only: authorizes, resolves inputs, calls the service, and
 * returns the right HTTP response.
 *
 * DomainException handling:
 *   ReservationService throws DomainException for invalid state transitions,
 *   overpayments, double-conversions, and double-cancel attempts. We catch
 *   those here and return 422 JSON. All other exceptions bubble to the global
 *   handler in bootstrap/app.php.
 *
 * Route-model binding:
 *   Laravel resolves {reservation} via the BelongsToTenant global scope, so any
 *   id belonging to a different tenant naturally 404s before we get here.
 */
final class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly ReservationRepositoryInterface $reservations,
    ) {}

    /**
     * Paginated list of reservations with per-status counts for tab rendering.
     *
     * Query params:
     *   ?branch_id=    — filter to a specific branch
     *   ?status=       — filter to one status (counts always span all statuses)
     *   ?customer_id=  — filter to a specific customer
     *   ?date_from=    — ISO date lower bound on event_date
     *   ?date_to=      — ISO date upper bound on event_date
     *   ?search=       — LIKE match on reservation_number
     *   ?per_page=     — page size (1–100, default 20)
     */
    public function index(Request $request): ReservationCollection
    {
        $this->authorize('viewAny', Reservation::class);

        $filters = [
            'branch_id' => $request->query('branch_id'),
            'status' => $request->query('status'),
            'customer_id' => $request->query('customer_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', '20'),
        ];

        // Eager-load to avoid N+1 on the list page.
        $paginator = $this->reservations->paginate($filters);
        $paginator->getCollection()->load(['branch', 'customer', 'assignee']);

        // Status counts honor all filters EXCEPT status + per_page, so tabs always show
        // correct totals regardless of which tab (status) is active.
        $countsFilters = array_diff_key($filters, ['status' => true, 'per_page' => true]);
        $statusCounts = $this->reservations->statusCounts($countsFilters);

        return (new ReservationCollection($paginator))
            ->additional(['status_counts' => $statusCounts]);
    }

    /**
     * Full reservation detail including payments, timeline, customer, branch, and assignee.
     *
     * Route-model binding resolves the reservation through BelongsToTenant — cross-tenant
     * ids are automatically 404.
     */
    public function show(Reservation $reservation): ReservationResource
    {
        $this->authorize('view', $reservation);

        $reservation->load([
            'payments.recorder',
            'statusHistory.user',
            'customer',
            'branch',
            'assignee',
            'creator',
            'convertedOrder',
        ]);

        return new ReservationResource($reservation);
    }

    /**
     * Capture a new reservation for the current tenant.
     *
     * Delegates to ReservationService::capture() which claims a sequence number,
     * creates the reservation, and writes the initial history row — all atomically.
     */
    public function store(StoreReservationRequest $request): ReservationResource|JsonResponse
    {
        $this->authorize('create', Reservation::class);

        $reservation = $this->reservationService->capture(
            data: $request->validated(),
            actor: $request->user(),
        );

        $reservation->load(['branch', 'customer', 'assignee', 'creator', 'statusHistory.user']);

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Advance or change the reservation's status.
     *
     * When the target status is 'confirmed', the request is routed through
     * ReservationService::confirm() which enforces the deposit coverage rule.
     * A 'force' boolean in the request body allows admin override.
     *
     * All other transitions use ReservationService::transitionTo() directly.
     * Invalid transitions return 422 with the DomainException message so the
     * UI can surface it to the user.
     */
    public function transition(TransitionReservationRequest $request, Reservation $reservation): ReservationResource|JsonResponse
    {
        $this->authorize('update', $reservation);

        $data = $request->validated();
        $toStatus = (string) $data['status'];
        $note = $data['note'] ?? null;
        $force = (bool) ($data['force'] ?? false);

        try {
            if ($toStatus === 'confirmed') {
                $updated = $this->reservationService->confirm(
                    reservation: $reservation,
                    actor: $request->user(),
                    force: $force,
                    note: $note,
                );
            } else {
                $updated = $this->reservationService->transitionTo(
                    reservation: $reservation,
                    toStatus: $toStatus,
                    actor: $request->user(),
                    note: $note,
                );
            }
        } catch (DomainException $e) {
            Log::warning('Reservation transition rejected', [
                'reservation_id' => $reservation->id,
                'reservation_number' => $reservation->reservation_number,
                'requested_status' => $toStatus,
                'current_status' => $reservation->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'reservations.invalid_transition',
            ], 422);
        }

        $updated->load(['branch', 'customer', 'assignee', 'statusHistory.user', 'payments.recorder']);

        return new ReservationResource($updated);
    }

    /**
     * Record a partial payment against the reservation.
     *
     * After recording, deposit_paid_cents is authoritatively recomputed from the
     * DB sum (not incremented) — see ReservationService::recordPayment(). The
     * response includes the updated payments list so the UI can refresh the balance
     * display without a second round-trip.
     *
     * Overpayment and invalid payment method are surfaced as 422.
     */
    public function recordPayment(RecordPaymentRequest $request, Reservation $reservation): ReservationResource|JsonResponse
    {
        $this->authorize('update', $reservation);

        $data = $request->validated();

        $paidAt = isset($data['paid_at']) ? new \DateTimeImmutable((string) $data['paid_at']) : null;

        try {
            $this->reservationService->recordPayment(
                reservation: $reservation,
                amountCents: (int) $data['amount_cents'],
                paymentMethod: (string) $data['payment_method'],
                actor: $request->user(),
                reference: $data['reference'] ?? null,
                paidAt: $paidAt,
            );
        } catch (DomainException|InvalidArgumentException $e) {
            Log::warning('Reservation payment rejected', [
                'reservation_id' => $reservation->id,
                'reservation_number' => $reservation->reservation_number,
                'amount_cents' => $data['amount_cents'],
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'reservations.payment_rejected',
            ], 422);
        }

        $reservation->refresh()->load([
            'payments.recorder',
            'statusHistory.user',
            'branch',
            'customer',
            'assignee',
        ]);

        return new ReservationResource($reservation);
    }

    /**
     * Convert the reservation to an Order.
     *
     * Delegates to ReservationService::convertToOrder() which atomically creates
     * the linked Order and transitions the reservation to 'delivered'. The
     * response returns the resulting order's id and number so the frontend can
     * navigate to the order detail page.
     *
     * Idempotency: a second call on an already-converted reservation returns 422
     * with the DomainException message ("already converted").
     */
    public function convert(Reservation $reservation, Request $request): JsonResponse
    {
        $this->authorize('update', $reservation);

        try {
            $order = $this->reservationService->convertToOrder(
                reservation: $reservation,
                actor: $request->user(),
            );
        } catch (DomainException $e) {
            Log::warning('Reservation conversion rejected', [
                'reservation_id' => $reservation->id,
                'reservation_number' => $reservation->reservation_number,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'reservations.cannot_convert',
            ], 422);
        }

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
        ]);
    }

    /**
     * Assign (or un-assign) a team member to the reservation.
     *
     * PATCH /api/v1/reservations/{reservation}/assignee
     * Body: { assigned_to: int|null }
     *
     * Passing null un-assigns the current assignee. The ReservationService enforces
     * that the assignee belongs to the same tenant — a cross-tenant user is rejected
     * with 422. Returns the full ReservationResource after update.
     */
    public function assign(Request $request, Reservation $reservation): ReservationResource|JsonResponse
    {
        $this->authorize('update', $reservation);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = isset($validated['assigned_to'])
            ? User::find((int) $validated['assigned_to'])
            : null;

        try {
            $updated = $this->reservationService->assign(
                reservation: $reservation,
                assignee: $assignee,
                actor: $request->user(),
            );
        } catch (DomainException $e) {
            Log::warning('Reservation assignment rejected', [
                'reservation_id' => $reservation->id,
                'assigned_to' => $validated['assigned_to'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'reservations.invalid_assignment',
            ], 422);
        }

        $updated->load(['branch', 'customer', 'assignee', 'statusHistory.user', 'payments.recorder']);

        return new ReservationResource($updated);
    }

    /**
     * Cancel the reservation.
     *
     * Delivered and already-cancelled reservations throw DomainException from
     * the service — surfaced as 422 here.
     */
    public function cancel(Reservation $reservation, Request $request): ReservationResource|JsonResponse
    {
        $this->authorize('update', $reservation);

        try {
            $this->reservationService->cancel(
                reservation: $reservation,
                user: $request->user(),
            );
        } catch (DomainException $e) {
            Log::warning('Reservation cancellation rejected', [
                'reservation_id' => $reservation->id,
                'reservation_number' => $reservation->reservation_number,
                'current_status' => $reservation->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'reservations.cannot_cancel',
            ], 422);
        }

        $reservation->refresh()->load([
            'branch',
            'customer',
            'assignee',
            'statusHistory.user',
            'payments.recorder',
        ]);

        return new ReservationResource($reservation);
    }
}
