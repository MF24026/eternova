<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Inventory\Models\BranchInventory;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @mixin BranchInventory
 */
final class BranchInventoryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'product_variant_id' => $this->product_variant_id,
            'tenant_id' => $this->tenant_id,
            'quantity' => $this->quantity,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'slug' => $this->branch->slug,
            ]),
            'product_variant' => $this->whenLoaded('productVariant', fn () => [
                'id' => $this->productVariant->id,
                'sku' => $this->productVariant->sku,
                'options' => $this->productVariant->options,
                'product' => $this->productVariant->relationLoaded('product')
                    ? ['id' => $this->productVariant->product->id, 'name' => $this->productVariant->product->name]
                    : null,
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
