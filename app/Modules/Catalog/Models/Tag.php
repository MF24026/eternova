<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Catalog\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Tag extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tag')
            ->withTimestamps();
    }
}
