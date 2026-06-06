<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @extends BaseResource
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
