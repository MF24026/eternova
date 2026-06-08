<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\StoreProductImageRequest;
use App\Modules\Catalog\Http\Resources\ProductImageResource;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImageService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductImageService $imageService,
    ) {}

    /**
     * Upload an image for a product.
     *
     * Multipart POST with field "image". Returns the created image object.
     */
    public function store(StoreProductImageRequest $request, Product $product): JsonResponse
    {
        // Double-layer authorization: FormRequest already checked, explicit gate here.
        $this->authorize('update', $product);

        $file = $request->file('image');

        try {
            $urls = $this->imageService->upload($product, $file);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        $this->imageService->attachToGallery($product, $urls);

        return (new ProductImageResource($urls))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Delete an image from the product gallery and storage.
     *
     * Expects JSON body: { "url": "<full-size URL>" }
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'url' => ['required', 'string'],
        ]);

        try {
            $this->imageService->deleteImage($product, $validated['url']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return response()->json(null, 204);
    }

    /**
     * Reorder the gallery.
     *
     * Expects JSON body: { "urls": ["<full-url-1>", "<full-url-2>", ...] }
     */
    public function reorder(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'urls' => ['required', 'array'],
            'urls.*' => ['string'],
        ]);

        $this->imageService->reorderGallery($product, $validated['urls']);

        return response()->json(null, 204);
    }

    /**
     * Set the default image for a product.
     *
     * Expects JSON body: { "url": "<full-size URL>" }
     */
    public function setDefault(Request $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'url' => ['required', 'string'],
        ]);

        try {
            $this->imageService->setAsDefault($product, $validated['url']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $product->refresh();

        return new ProductResource($product);
    }
}
