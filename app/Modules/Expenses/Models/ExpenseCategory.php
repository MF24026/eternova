<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    protected $fillable = [
        'name',
        'type',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
