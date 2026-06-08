<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\Storefront;

use App\Http\Resources\Api\V1\BaseCollection;

/**
 * Paginated list of public products.
 *
 * Each item is transformed by StorefrontProductResource.
 * Variants are NOT included in list responses — only on the detail endpoint.
 */
final class StorefrontProductCollection extends BaseCollection
{
    public $collects = StorefrontProductResource::class;
}
