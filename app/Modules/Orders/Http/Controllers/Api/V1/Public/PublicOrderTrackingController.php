<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Resources\OrderTrackingResource;
use App\Modules\Orders\Models\Order;

/**
 * Public, login-free order tracking endpoint.
 *
 * No auth middleware — this controller is intentionally accessible to anyone
 * who holds the tracking_token.  The token is 32 chars of url-safe randomness
 * (~192 bits of entropy), making enumeration attacks infeasible in practice.
 *
 * Multi-tenant safety: the BelongsToTenant global scope is active because the
 * route is wrapped in the 'tenant' middleware (EnsureTenant).  A token that
 * belongs to tenant A, requested on tenant B's subdomain, naturally resolves to
 * null (the scope filters it out) → 404.  No extra cross-tenant check needed.
 *
 * The response payload is strictly sanitised — see OrderTrackingResource.
 */
final class PublicOrderTrackingController extends Controller
{
    public function show(string $token): OrderTrackingResource
    {
        $order = Order::with(['branch', 'statusHistory', 'tenant'])
            ->where('tracking_token', $token)
            ->first();

        abort_if($order === null, 404);

        return new OrderTrackingResource($order);
    }
}
