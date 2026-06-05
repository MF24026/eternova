<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Transforms a PersonalAccessToken model into a JSON representation.
 *
 * The plaintext token is NOT included here — it is only available at creation
 * time and is returned separately in the create-token response under the key
 * `plain_text_token`. This resource is used for listing/revoking existing tokens.
 *
 * @property-read PersonalAccessToken $resource
 */
final class TokenResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'abilities' => $this->resource->abilities,
            'last_used_at' => $this->resource->last_used_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
