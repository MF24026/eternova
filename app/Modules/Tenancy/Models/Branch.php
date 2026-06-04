<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use HasUlids;

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'address',
        'phone',
        'is_main',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_main' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * The tenant() relationship is provided by BelongsToTenant, but we keep the
     * explicit method here for IDE discoverability and to satisfy static analysis.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
