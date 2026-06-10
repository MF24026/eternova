<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
final class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_main' => (bool) $this->is_main,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
