<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Catalog\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Category extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_url',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The direct parent category, or null for root categories.
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct subcategories ordered for display.
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Products in this category, with display sort order from the pivot.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product')
            ->using(CategoryProduct::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * A leaf category has no children — safe to assign products directly.
     */
    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /**
     * Nesting depth: 0 = root, 1 = first-level child, 2 = grandchild.
     * Max depth of 2 is enforced at the service layer (#32).
     */
    public function depth(): int
    {
        if ($this->parent_id === null) {
            return 0;
        }

        $parent = $this->parent()->first();

        if ($parent === null || $parent->parent_id === null) {
            return 1;
        }

        return 2;
    }
}
