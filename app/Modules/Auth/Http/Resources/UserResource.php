<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Transforms a User model into a JSON representation.
 *
 * Tenants array includes each tenant the user belongs to, with their role and
 * join date. The current tenant (from current_tenant()) is flagged is_current: true
 * so the SPA can pre-select the right context.
 *
 * @property-read User $resource
 */
final class UserResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        $currentTenant = current_tenant();

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'avatar_url' => $this->resource->avatar_url,
            'email_verified_at' => $this->resource->email_verified_at?->toIso8601String(),
            'is_super_admin' => $this->resource->is_super_admin,
            'tenants' => $this->resource->tenants->map(static function ($tenant) use ($currentTenant) {
                return [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'business_name' => $tenant->business_name,
                    'role' => $tenant->pivot->role,
                    'joined_at' => $tenant->pivot->joined_at?->toIso8601String(),
                    'is_current' => $currentTenant !== null && $tenant->id === $currentTenant->id,
                ];
            })->values()->all(),
        ];
    }
}
