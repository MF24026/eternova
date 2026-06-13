<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Models;

use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Quotations\QuotationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line item on a quotation.
 *
 * description is a snapshot of the product name at quote-creation time.
 * The historical PDF stays accurate even if the product record changes later.
 *
 * unit_price_cents and line_total_cents are centavos. Never expose raw values
 * in API responses — format in API Resources.
 *
 * sort_order controls the display sequence in the PDF and the UI builder.
 */
final class QuotationItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuotationItemFactory> */
    use HasFactory;

    protected static function newFactory(): QuotationItemFactory
    {
        return QuotationItemFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'quotation_id',
        'product_id',
        'description',
        'quantity',
        'unit_price_cents',
        'line_total_cents',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity'         => 'integer',
        'unit_price_cents' => 'integer',
        'line_total_cents' => 'integer',
        'sort_order'       => 'integer',
    ];

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * The catalog product this line is linked to.
     *
     * Null for free-text lines not tied to a specific product.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
