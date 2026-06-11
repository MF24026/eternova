<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Orders\Http\Requests\AssignOrderRequest;
use App\Modules\Orders\Http\Requests\TransitionOrderRequest;
use App\Modules\Orders\Http\Resources\OrderCollection;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Order management REST endpoints.
 *
 * Thin controller — every business rule lives in OrderService.
 * This class only: authorizes, resolves inputs, calls the service, and
 * returns the right HTTP response.
 *
 * DomainException handling:
 *   OrderService throws DomainException for invalid state transitions,
 *   cross-tenant assignments, and double-cancel attempts. We catch those
 *   here and return 422 JSON. All other exceptions bubble to the global
 *   handler in bootstrap/app.php.
 *
 * Route-model binding:
 *   Laravel resolves {order} via the BelongsToTenant global scope, so any id
 *   belonging to a different tenant naturally 404s before we get here.
 */
final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderRepositoryInterface $orders,
    ) {}

    /**
     * Paginated list of orders with per-status counts for tab rendering.
     *
     * Query params:
     *   ?branch_id=    — filter to a specific branch
     *   ?status=       — filter to one status (counts always span all statuses)
     *   ?customer_id=  — filter to a specific customer
     *   ?date_from=    — ISO date lower bound on created_at
     *   ?date_to=      — ISO date upper bound on created_at
     *   ?search=       — LIKE match on order_number
     *   ?per_page=     — page size (1–100, default 20)
     */
    public function index(Request $request): OrderCollection
    {
        $this->authorize('viewAny', Order::class);

        $filters = [
            'branch_id'   => $request->query('branch_id'),
            'status'      => $request->query('status'),
            'customer_id' => $request->query('customer_id'),
            'date_from'   => $request->query('date_from'),
            'date_to'     => $request->query('date_to'),
            'search'      => $request->query('search'),
            'per_page'    => $request->query('per_page', '20'),
        ];

        // Eager-load to avoid N+1 on the list page.
        $paginator = $this->orders->paginate($filters);
        $paginator->getCollection()->load(['branch', 'customer', 'assignee']);

        // Status counts honor all filters EXCEPT status, so tabs always show
        // correct totals regardless of which tab (status) is active.
        $countsFilters = array_diff_key($filters, ['status' => true, 'per_page' => true]);
        $statusCounts = $this->orders->statusCounts($countsFilters);

        return (new OrderCollection($paginator))
            ->additional(['status_counts' => $statusCounts]);
    }

    /**
     * Full order detail including items, timeline, customer, branch, and assignee.
     *
     * Route-model binding resolves the order through BelongsToTenant — cross-tenant
     * ids are automatically 404.
     */
    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        $order->load([
            'items',
            'statusHistory.user',
            'customer',
            'branch',
            'user',
            'assignee',
        ]);

        return new OrderResource($order);
    }

    /**
     * Advance or change the order's status.
     *
     * The state machine is enforced by OrderService::transitionTo(). Invalid
     * transitions (e.g. pending → delivered) return 422 with the DomainException
     * message so the UI can surface it to the user.
     */
    public function transition(TransitionOrderRequest $request, Order $order): OrderResource|JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validated();

        try {
            $updated = $this->orderService->transitionTo(
                order: $order,
                toStatus: $data['status'],
                actor: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (DomainException $e) {
            Log::warning('Order transition rejected', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'requested_status' => $data['status'],
                'current_status' => $order->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'orders.invalid_transition',
            ], 422);
        }

        $updated->load(['branch', 'customer', 'assignee', 'statusHistory.user', 'items']);

        return new OrderResource($updated);
    }

    /**
     * Assign (or un-assign) a staff member to the order.
     *
     * Passing assigned_to: null clears the assignment. The service enforces
     * that the assignee belongs to the same tenant as the order.
     */
    public function assign(AssignOrderRequest $request, Order $order): OrderResource|JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validated();

        $assignee = isset($data['assigned_to'])
            ? User::withoutGlobalScope(TenantScope::class)->find((int) $data['assigned_to'])
            : null;

        try {
            $updated = $this->orderService->assign(
                order: $order,
                assignee: $assignee,
                actor: $request->user(),
            );
        } catch (DomainException $e) {
            Log::warning('Order assignment rejected', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'assigned_to' => $data['assigned_to'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'orders.invalid_assignment',
            ], 422);
        }

        $updated->load(['branch', 'customer', 'assignee', 'statusHistory.user', 'items']);

        return new OrderResource($updated);
    }

    /**
     * Cancel the order.
     *
     * Delivered orders and already-cancelled orders throw DomainException from
     * the service — surfaced as 422 here.
     */
    public function cancel(Request $request, Order $order): OrderResource|JsonResponse
    {
        $this->authorize('update', $order);

        try {
            $this->orderService->cancel(order: $order, user: $request->user());
        } catch (DomainException $e) {
            Log::warning('Order cancellation rejected', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'current_status' => $order->status,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'orders.cannot_cancel',
            ], 422);
        }

        $order->refresh()->load(['branch', 'customer', 'assignee', 'statusHistory.user', 'items']);

        return new OrderResource($order);
    }
}
