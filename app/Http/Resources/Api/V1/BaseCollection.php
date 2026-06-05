<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base paginated-collection transformer.
 *
 * Every successful paginated response follows the envelope:
 *
 *   {
 *     "data": [ ... ],
 *     "links": {
 *       "first": "...?page=1",
 *       "last":  "...?page=12",
 *       "prev":  null,
 *       "next":  "...?page=2"
 *     },
 *     "meta": {
 *       "current_page": 1,
 *       "per_page": 20,
 *       "total": 240,
 *       "tenant_id": "01HW9...",   // only when a tenant context is active
 *       "request_id": "uuid-v4"
 *     }
 *   }
 *
 * Laravel's ResourceCollection already adds "links" and basic "meta" for
 * paginated results. We augment meta with tenant_id and request_id.
 */
abstract class BaseCollection extends ResourceCollection
{
    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        $meta = [
            'request_id' => $request->header('X-Request-Id', ''),
        ];

        $tenant = current_tenant();

        if ($tenant !== null) {
            $meta['tenant_id'] = $tenant->id;
        }

        return [
            'meta' => $meta,
        ];
    }

    /**
     * Merge tenant_id and request_id into the meta block that Laravel's
     * ResourceCollection already builds for paginated results.
     *
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, mixed $paginated, mixed $default): array
    {
        /** @var array<string, mixed> $defaultArray */
        $defaultArray = $default;

        $extra = [
            'request_id' => $request->header('X-Request-Id', ''),
        ];

        $tenant = current_tenant();

        if ($tenant !== null) {
            $extra['tenant_id'] = $tenant->id;
        }

        /** @var array<string, mixed> $existingMeta */
        $existingMeta = $defaultArray['meta'] ?? [];

        $defaultArray['meta'] = array_merge($existingMeta, $extra);

        return $defaultArray;
    }
}
