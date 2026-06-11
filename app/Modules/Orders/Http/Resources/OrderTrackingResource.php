<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Sanitised public tracking payload — safe to display to the end customer and
 * safe to screenshot / share without leaking any PII or business data.
 *
 * === WHAT IS INCLUDED ===
 *   order_number   — publicly visible identifier (already on receipts)
 *   status         — current order status (the point of the tracking page)
 *   branch_name    — just the name string; no ids, no addresses
 *   created_at     — ISO 8601; tells the customer when the order was placed
 *   timeline       — filtered status transitions (real transitions only)
 *   brand          — tenant branding so the tracking page looks on-brand
 *                    (reuses the exact same field shape as StorefrontTenantResource)
 *
 * === WHAT IS EXPLICITLY EXCLUDED ===
 *   customer.*             — name, phone, email, whatsapp, address (PII)
 *   total_cents / subtotal_cents / tax_cents / discount_cents — financial data
 *   items / product_snapshot — product catalogue data
 *   notes / admin_notes    — internal staff notes
 *   assignee / user_id / assigned_to — internal staff attribution
 *   payment_method / payment_status  — billing data
 *   tracking_token                   — the token IS the credential, never echo it back
 *   id / tenant_id                   — internal primary keys
 *
 * === TIMELINE FILTERING ===
 *   Assignment-only rows (from_status === to_status) are excluded because they
 *   represent internal staff assignment events, not customer-visible progress.
 *   Only real status changes (from_status IS NULL — birth row — or from_status ≠ to_status)
 *   are included.
 *
 * @property Order $resource
 */
final class OrderTrackingResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'branch_name' => $order->branch?->name,
            'created_at' => $order->created_at?->toIso8601String(),
            'timeline' => $this->buildTimeline($order),
            'brand' => $this->buildBrand($order),
        ];
    }

    /**
     * Build the public timeline from the statusHistory relation.
     *
     * Includes only real status changes:
     *   - from_status IS NULL  → the initial creation entry (order born into to_status)
     *   - from_status ≠ to_status  → a genuine status transition
     *
     * Assignment-only rows (from_status === to_status, written by OrderService::assign())
     * are intentionally excluded — they carry staff-assignment context, not customer-visible
     * progress milestones.
     *
     * The relation is already ordered oldest-first (see Order::statusHistory()).
     *
     * @return list<array{status: string, at: string|null}>
     */
    private function buildTimeline(Order $order): array
    {
        return $order->statusHistory
            ->filter(static fn ($entry) => $entry->from_status === null
                || $entry->from_status !== $entry->to_status)
            ->map(static fn ($entry) => [
                'status' => $entry->to_status,
                'at' => $entry->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Build the tenant brand block, reusing the exact same field shape as
     * StorefrontTenantResource so the tracking page can render on-brand without
     * a separate branding API call.
     *
     * Fields sourced from Tenant::$brand_extra (a JSON column) are accessed
     * defensively with null-coalescing because the column may be empty on new
     * tenants that have not yet configured their brand.
     *
     * @return array<string, mixed>
     */
    private function buildBrand(Order $order): array
    {
        /** @var Tenant|null $tenant */
        $tenant = $order->tenant;

        if ($tenant === null) {
            return [];
        }

        /** @var array<string, mixed> $brandExtra */
        $brandExtra = $tenant->brand_extra ?? [];

        return [
            'slug' => $tenant->slug,
            'business_name' => $tenant->business_name,
            'logo_url' => $tenant->logo_url,
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'favicon_url' => $tenant->favicon_url,
            'currency' => $tenant->currency,
            'country_code' => $tenant->country_code,
            'language' => $tenant->language,
            'whatsapp_number' => $brandExtra['whatsapp_number'] ?? null,
            'tagline' => $brandExtra['tagline'] ?? null,
        ];
    }
}
