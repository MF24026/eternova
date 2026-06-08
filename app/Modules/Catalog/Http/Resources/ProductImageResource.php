<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use Illuminate\Http\Request;

/**
 * Transforms a single gallery image entry (the thumbnail/medium/full object)
 * into the standard API envelope.
 *
 * Input (the $resource) is an associative array:
 *   ['thumbnail' => '...', 'medium' => '...', 'full' => '...']
 *
 * @extends BaseResource
 */
final class ProductImageResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var array{thumbnail: string, medium: string, full: string} $image */
        $image = $this->resource;

        return [
            'thumbnail' => $image['thumbnail'],
            'medium' => $image['medium'],
            'full' => $image['full'],
        ];
    }
}
