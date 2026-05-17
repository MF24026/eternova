<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
