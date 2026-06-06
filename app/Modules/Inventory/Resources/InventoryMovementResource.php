<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @mixin InventoryMovement
 */
final class InventoryMovementResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
            'product_variant_id' => $this->product_variant_id,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'notes' => $this->notes,
            'user_id' => $this->user_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'product_variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'sku' => $this->productVariant->sku,
            ]),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
