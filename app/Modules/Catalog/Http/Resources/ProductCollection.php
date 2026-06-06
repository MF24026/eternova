<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Http\Resources\Api\V1\BaseCollection;
use Illuminate\Http\Request;

final class ProductCollection extends BaseCollection
{
    /** @var string */
    public $collects = ProductResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
