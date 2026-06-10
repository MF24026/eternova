<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Resources;

use App\Http\Resources\Api\V1\BaseCollection;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Paginated POS product list.
 *
 * The stock map (Collection<int, int> keyed by variant_id → available qty)
 * is set via additional(['stock' => $map]) and threaded into each item.
 */
final class PosProductCollection extends BaseCollection
{
    /**
     * @return array<int, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, int>|null $stock */
        $stock = $this->additional['stock'] ?? null;

        return $this->collection
            ->map(static function (mixed $product) use ($stock): array {
                /** @var Product $product */
                return (new PosProductResource($product))
                    ->additional($stock !== null ? ['stock' => $stock] : [])
                    ->toArray(request());
            })
            ->values()
            ->all();
    }
}
