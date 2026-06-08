<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\ProductCollection;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Repositories\ProductRepositoryInterface;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductService $productService,
    ) {}

    /**
     * Paginated list of products with optional filters.
     */
    public function index(Request $request): ProductCollection
    {
        $this->authorize('viewAny', Product::class);

        $filters = [
            'search' => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'tag_id' => $request->query('tag_id'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'is_featured' => $request->has('is_featured')
                ? filter_var($request->query('is_featured'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'with' => $request->query('with'),
            'per_page' => $request->query('per_page', '20'),
        ];

        return new ProductCollection(
            $this->products->paginate($filters)
        );
    }

    /**
     * Single product with all relations.
     */
    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        $loaded = $this->products->findWithRelations($product->id);

        return new ProductResource($loaded ?? $product);
    }

    /**
     * Create a product atomically (product + options + variants + categories + tags).
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->productService->create($request->validated());
        $loaded = $this->products->findWithRelations($product->id);

        return (new ProductResource($loaded ?? $product))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing product.
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $updated = $this->productService->update($product, $request->validated());
        $loaded = $this->products->findWithRelations($updated->id);

        return new ProductResource($loaded ?? $updated);
    }

    /**
     * Soft-delete a product (cascades to its variants).
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->productService->delete($product);

        return response()->json(null, 204);
    }

    /**
     * Restore a soft-deleted product and its variants.
     */
    public function restore(Product $product): ProductResource
    {
        $this->authorize('restore', $product);

        $this->productService->restore($product);

        $loaded = $this->products->findWithRelations($product->id);

        return new ProductResource($loaded ?? $product);
    }
}
