<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @property Customer $resource
 */
final class CustomerResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'whatsapp' => $customer->whatsapp,
            'address' => $customer->address,
            'notes' => $customer->notes,
            'total_purchases' => $customer->total_purchases,
            'last_purchase_at' => $customer->last_purchase_at?->toIso8601String(),
            'deleted_at' => $customer->deleted_at?->toIso8601String(),
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
