<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Http\Resources\Api\V1\BaseCollection;
use Illuminate\Http\Request;

final class CustomerCollection extends BaseCollection
{
    public $collects = CustomerResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
