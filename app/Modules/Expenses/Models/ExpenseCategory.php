<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Expenses\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-tenant expense category for classifying business expenditures.
 *
 * Categories belong to one tenant and are personalizable — a florist might
 * rename "rent" to "local" while a gift shop keeps the default label.
 * is_active allows retiring a category without hard-deleting: historical
 * expense rows keep their FK intact.
 *
 * The `type` enum drives grouping in the monthly expense report (S6-E5).
 * It represents the financial bucket (operating, products, payroll, rent, other)
 * independently of the human-readable name the tenant assigns.
 */
final class ExpenseCategory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    protected static function newFactory(): ExpenseCategoryFactory
    {
        return ExpenseCategoryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * All expenses classified under this category, including soft-deleted ones.
     *
     * withTrashed() is intentional: a retired category (is_active=false) still
     * has historical expense rows that should be queryable for reporting purposes.
     *
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
