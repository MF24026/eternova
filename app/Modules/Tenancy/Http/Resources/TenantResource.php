<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Transforms a Tenant model into a JSON representation.
 *
 * @property-read Tenant $resource
 */
final class TenantResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'email' => $this->resource->email,
            'status' => $this->resource->status,
            'business_name' => $this->resource->business_name,
            'country_code' => $this->resource->country_code,
            'currency' => $this->resource->currency,
            'language' => $this->resource->language,
            'timezone' => $this->resource->timezone,
            'trial_ends_at' => $this->resource->trial_ends_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
