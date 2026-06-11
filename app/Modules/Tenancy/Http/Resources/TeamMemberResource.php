<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenant member, consumed by selectors that assign work to staff (e.g. the
 * order assignee picker). The per-tenant role comes from the tenant_users pivot.
 *
 * @mixin User
 */
final class TeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var string|null $role */
        $role = $this->pivot?->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $role,
        ];
    }
}
