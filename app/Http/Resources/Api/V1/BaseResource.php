<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base single-resource transformer.
 *
 * Every successful singular response follows the envelope:
 *
 *   {
 *     "data": { ... },
 *     "meta": {
 *       "tenant_id": "01HW9...",   // only when a tenant context is active
 *       "request_id": "uuid-v4"
 *     }
 *   }
 *
 * Concrete resources extend this class and implement toResourceArray() instead
 * of toArray() so the envelope is always applied consistently.
 */
abstract class BaseResource extends JsonResource
{
    /**
     * Return the attributes that should appear inside "data".
     *
     * @return array<string, mixed>
     */
    abstract public function toResourceArray(Request $request): array;

    /**
     * @return array<string, mixed>
     */
    final public function toArray(Request $request): array
    {
        return $this->toResourceArray($request);
    }

    /**
     * @return array<string, mixed>
     */
    final public function with(Request $request): array
    {
        return [
            'meta' => $this->buildMeta($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMeta(Request $request): array
    {
        $meta = [
            'request_id' => $request->header('X-Request-Id', ''),
        ];

        $tenant = current_tenant();

        if ($tenant !== null) {
            $meta['tenant_id'] = $tenant->id;
        }

        return $meta;
    }

    public function toResponse(mixed $request): JsonResponse
    {
        return parent::toResponse($request);
    }
}
