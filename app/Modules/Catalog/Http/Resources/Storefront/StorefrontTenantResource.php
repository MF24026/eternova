<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\Storefront;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Public branding snapshot for the storefront.
 *
 * Exposes only what the public UI needs: identity, colors, contact, locale.
 *
 * NEVER exposed: email, status, trial_ends_at, plan, subscriptions,
 * locale_extra internals, internal tenant id beyond the public slug.
 *
 * @property Tenant $resource
 */
final class StorefrontTenantResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->resource;

        /** @var array<string, mixed> $brandExtra */
        $brandExtra = $tenant->brand_extra ?? [];

        return [
            'slug' => $tenant->slug,
            'business_name' => $tenant->business_name,
            'logo_url' => $tenant->logo_url,
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'favicon_url' => $tenant->favicon_url,
            'currency' => $tenant->currency,
            'country_code' => $tenant->country_code,
            'language' => $tenant->language,
            // Pulled from brand_extra so tenants can configure it from Settings
            'whatsapp_number' => $brandExtra['whatsapp_number'] ?? null,
            'tagline' => $brandExtra['tagline'] ?? null,
            'description' => $brandExtra['description'] ?? null,
        ];
    }
}
