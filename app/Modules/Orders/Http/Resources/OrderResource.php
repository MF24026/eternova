<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Http\Request;

/**
 * Transforms a single Order for API output.
 *
 * Monetary values (subtotal_cents, tax_cents, discount_cents, total_cents) are
 * exposed as raw centavo integers — consistent with the POS module convention.
 * Formatting for display (currency symbol, decimal places) is the frontend's
 * responsibility via useFormatCurrency composable.
 *
 * allowed_transitions is resolved at serialisation time so the frontend can
 * render only the valid action buttons without knowing the state machine.
 *
 * Nested relations (branch, customer, assignee, items, statusHistory) are
 * conditionally included only when explicitly loaded — prevents accidental N+1
 * if the caller forgets to eager-load.
 *
 * OrderService is resolved from the container at serialisation time rather than
 * injected via constructor. ResourceCollection uses mapInto() to instantiate
 * resources, which calls new OrderResource($model) — a custom constructor
 * signature would receive the collection index as the second argument and throw.
 *
 * @property Order $resource
 */
final class OrderResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $service = app(OrderService::class);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'source' => $order->source,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal_cents' => $order->subtotal_cents,
            'tax_cents' => $order->tax_cents,
            'discount_cents' => $order->discount_cents,
            'total_cents' => $order->total_cents,
            'notes' => $order->notes,
            'allowed_transitions' => $service->allowedTransitions($order),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),

            'branch' => $this->when(
                $order->relationLoaded('branch') && $order->branch !== null,
                static fn () => [
                    'id' => $order->branch?->id,
                    'name' => $order->branch?->name,
                ],
            ),

            'customer' => $this->when(
                $order->relationLoaded('customer') && $order->customer !== null,
                static fn () => [
                    'id' => $order->customer?->id,
                    'name' => $order->customer?->name,
                    'phone' => $order->customer?->phone,
                ],
            ),

            'assignee' => $this->when(
                $order->relationLoaded('assignee'),
                static fn () => $order->assignee !== null
                    ? ['id' => $order->assignee->id, 'name' => $order->assignee->name]
                    : null,
            ),

            'items' => $this->when(
                $order->relationLoaded('items'),
                fn () => OrderItemResource::collection($order->items),
            ),

            'status_history' => $this->when(
                $order->relationLoaded('statusHistory'),
                fn () => OrderStatusHistoryResource::collection($order->statusHistory),
            ),
        ];
    }
}
