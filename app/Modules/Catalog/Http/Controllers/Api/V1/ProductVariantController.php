<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\ReorderVariantsRequest;
use App\Modules\Catalog\Http\Requests\StoreVariantRequest;
use App\Modules\Catalog\Http\Requests\UpdateVariantRequest;
use App\Modules\Catalog\Http\Resources\ProductVariantResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductVariantService $variantService,
    ) {}

    /**
     * Add a new variant to an existing product.
     */
    public function store(StoreVariantRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $variant = $this->variantService->addVariant($product, $request->validated());

        Log::info('Variant added', [
            'variant_id' => $variant->id,
            'product_id' => $product->id,
            'sku' => $variant->sku,
        ]);

        return (new ProductVariantResource($variant))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a single variant.
     */
    public function update(UpdateVariantRequest $request, Product $product, ProductVariant $variant): ProductVariantResource
    {
        $this->authorize('update', $product);

        $this->assertVariantBelongsToProduct($variant, $product);

        $updated = $this->variantService->updateVariant($variant, $request->validated());

        return new ProductVariantResource($updated);
    }

    /**
     * Soft-delete a variant.
     */
    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $product);

        $this->assertVariantBelongsToProduct($variant, $product);

        $variant->delete();

        Log::info('Variant soft-deleted', [
            'variant_id' => $variant->id,
            'product_id' => $product->id,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Reorder variants by position.
     */
    public function reorder(ReorderVariantsRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        /** @var array<int, array{id: int, position: int}> $items */
        $items = $request->validated()['items'];

        $this->variantService->reorder($items);

        return response()->json(null, 204);
    }

    /**
     * Guard that prevents a variant of product B from being mutated via product A's route.
     * Route model binding resolves ProductVariant globally — the global scope on ProductVariant
     * does NOT exist (variants are scoped through Product), so we verify explicitly.
     */
    private function assertVariantBelongsToProduct(ProductVariant $variant, Product $product): void
    {
        if ($variant->product_id !== $product->id) {
            abort(404, "Variant #{$variant->id} does not belong to product #{$product->id}.");
        }
    }
}
