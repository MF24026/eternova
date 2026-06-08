<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Handles all image operations for products: upload, resize, attach to gallery,
 * set as default, delete from storage, and reorder.
 *
 * Gallery JSON shape (ADR-007 decision): an array of objects, each with
 * thumbnail / medium / full URLs. The frontend has all sizes without string
 * manipulation; srcset is trivial to build from the three keys.
 *
 * Example:
 *   [
 *     { "thumbnail": "...", "medium": "...", "full": "..." },
 *     { "thumbnail": "...", "medium": "...", "full": "..." },
 *   ]
 *
 * ADR-006 storage path: tenants/{tenant_id}/products/{product_id}/{size}/{uuid}.{ext}
 */
final readonly class ProductImageService
{
    private const SIZES = ['thumbnail', 'medium', 'full'];

    /**
     * Validate, resize to 3 sizes, store on the configured disk, and return
     * the URL object { thumbnail, medium, full }.
     *
     * @return array{thumbnail: string, medium: string, full: string}
     *
     * @throws DomainException when the file violates size, mime, or dimension rules
     */
    public function upload(Product $product, UploadedFile $file): array
    {
        $this->validateFile($file);

        $manager = new ImageManager(new Driver);

        $image = $manager->decode($file->getRealPath());

        $shortestSide = min($image->width(), $image->height());

        if ($shortestSide < config('catalog.image_min_dimension')) {
            throw new DomainException(
                'Image is too small. The shortest side must be at least '
                .config('catalog.image_min_dimension')
                ."px; uploaded image is {$shortestSide}px."
            );
        }

        $disk = config('catalog.image_disk');
        $sizes = config('catalog.image_sizes');
        $extension = $this->resolveExtension($file->getMimeType() ?? 'image/jpeg');
        $uuid = Str::uuid()->toString();
        $tenantId = $product->tenant_id;
        $productId = $product->id;

        $urls = [];

        foreach ($sizes as $sizeName => $sideLength) {
            // Cover-crop to a square so thumbnails are uniform and CDN-friendly.
            $resized = $manager->decode($file->getRealPath())
                ->cover($sideLength, $sideLength);

            $encoded = $resized->encodeUsingPath("dummy.{$extension}");

            $path = "tenants/{$tenantId}/products/{$productId}/{$sizeName}/{$uuid}.{$extension}";

            Storage::disk($disk)->put($path, $encoded->toString());

            $urls[$sizeName] = Storage::disk($disk)->url($path);
        }

        /** @var array{thumbnail: string, medium: string, full: string} $urls */
        Log::info('Product image uploaded', [
            'product_id' => $productId,
            'tenant_id' => $tenantId,
            'uuid' => $uuid,
            'disk' => $disk,
        ]);

        return $urls;
    }

    /**
     * Append an image object to the product's gallery JSON array.
     *
     * @param  array{thumbnail: string, medium: string, full: string}  $urls
     */
    public function attachToGallery(Product $product, array $urls): void
    {
        $gallery = $product->gallery ?? [];
        $gallery[] = $urls;

        $product->update(['gallery' => $gallery]);
    }

    /**
     * Set the product's default_image_url to the given full-size URL.
     */
    public function setAsDefault(Product $product, string $fullUrl): void
    {
        $this->assertUrlBelongsToGallery($product, $fullUrl);

        $product->update(['default_image_url' => $fullUrl]);

        Log::info('Product default image set', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
        ]);
    }

    /**
     * Remove a gallery entry and delete all three size files from disk.
     */
    public function deleteImage(Product $product, string $fullUrl): void
    {
        $this->assertUrlBelongsToGallery($product, $fullUrl);

        $gallery = array_values(
            array_filter(
                $product->gallery ?? [],
                static fn (array $entry): bool => $entry['full'] !== $fullUrl,
            )
        );

        $product->update(['gallery' => $gallery]);

        // Clear default_image_url if it pointed to this image.
        if ($product->default_image_url === $fullUrl) {
            $product->update(['default_image_url' => null]);
        }

        $this->deleteAllSizesFromDisk($fullUrl);

        Log::info('Product image deleted', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
        ]);
    }

    /**
     * Reorder the gallery to match the given ordered array of full-size URLs.
     * URLs not present in the current gallery are silently ignored.
     *
     * @param  list<string>  $orderedFullUrls
     */
    public function reorderGallery(Product $product, array $orderedFullUrls): void
    {
        $current = $product->gallery ?? [];

        // Index existing entries by full URL for O(1) lookup.
        $indexed = [];
        foreach ($current as $entry) {
            $indexed[$entry['full']] = $entry;
        }

        $reordered = [];
        foreach ($orderedFullUrls as $fullUrl) {
            if (isset($indexed[$fullUrl])) {
                $reordered[] = $indexed[$fullUrl];
            }
        }

        $product->update(['gallery' => $reordered]);

        Log::info('Product gallery reordered', [
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * @throws DomainException when file size or MIME type is not allowed
     */
    private function validateFile(UploadedFile $file): void
    {
        $maxBytes = config('catalog.image_max_bytes');
        $allowedMimes = config('catalog.image_allowed_mimes');

        if ($file->getSize() > $maxBytes) {
            $maxMb = round($maxBytes / 1024 / 1024, 1);
            throw new DomainException(
                "Image exceeds the maximum allowed size of {$maxMb} MB."
            );
        }

        $realMime = $file->getMimeType();

        if (! in_array($realMime, $allowedMimes, strict: true)) {
            $allowed = implode(', ', $allowedMimes);
            throw new DomainException(
                "Image type '{$realMime}' is not allowed. Accepted types: {$allowed}."
            );
        }
    }

    /**
     * Derive the file extension from a MIME type.
     * Falls back to 'jpg' for unknown types (should not happen after validateFile()).
     */
    private function resolveExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Assert that the given full URL exists in the product's gallery.
     *
     * @throws DomainException when the URL is not found
     */
    private function assertUrlBelongsToGallery(Product $product, string $fullUrl): void
    {
        $gallery = $product->gallery ?? [];
        $found = false;

        foreach ($gallery as $entry) {
            if (($entry['full'] ?? null) === $fullUrl) {
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw new DomainException(
                "The URL is not part of product #{$product->id}'s gallery."
            );
        }
    }

    /**
     * Delete the thumbnail, medium, and full files for an image, given its
     * full-size URL.
     *
     * The three size files share the same UUID and extension but differ only
     * in the {size} path segment:
     *   tenants/{t}/products/{p}/full/{uuid}.{ext}   ← given URL
     *   tenants/{t}/products/{p}/medium/{uuid}.{ext}
     *   tenants/{t}/products/{p}/thumbnail/{uuid}.{ext}
     */
    private function deleteAllSizesFromDisk(string $fullUrl): void
    {
        $disk = config('catalog.image_disk');
        $base = Storage::disk($disk)->url('');

        // Strip base URL to get the relative path on disk.
        $fullPath = ltrim(str_replace($base, '', $fullUrl), '/');

        foreach (self::SIZES as $sizeName) {
            $path = (string) preg_replace('#/full/#', "/{$sizeName}/", $fullPath, limit: 1);
            Storage::disk($disk)->delete($path);
        }
    }
}
