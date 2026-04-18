<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'expense_category_id',
        'description',
        'amount',
        'date',
        'receipt_image',
        'ocr_data',
        'is_verified',
        'vendor',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'date' => 'date',
        'ocr_data' => 'array',
        'is_verified' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
