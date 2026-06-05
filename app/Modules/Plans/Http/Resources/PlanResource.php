<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Plans\Models\Plan;
use Illuminate\Http\Request;

/**
 * Transforms a Plan model into the JSON representation exposed to the onboarding wizard.
 *
 * @property-read Plan $resource
 */
final class PlanResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'slug' => $this->resource->slug,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'price_monthly_cents' => $this->resource->price_monthly_cents,
            'price_yearly_cents' => $this->resource->price_yearly_cents,
            'currency' => $this->resource->currency,
            'features' => $this->resource->features ?? [],
            'limits' => $this->resource->limits ?? [],
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
