<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Quotations\QuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's quotation sent to a customer.
 *
 * Monetary values are always stored in centavos (int). Never expose raw _cents
 * columns in API responses — format in API Resources.
 *
 * tax_rate_bps stores the tax rate in basis points (100 bps = 1%); 1300 = 13% IVA SV.
 *
 * quotation_number is generated per-tenant (COT-{year}-{seq}) using a locked
 * sequence row in quotation_sequences. See QuotationService::nextQuotationNumber().
 *
 * Lifecycle: draft → sent → accepted | rejected | expired (terminal states).
 * On acceptance, convertedOrder() links to the Order created from the quotation.
 */
final class Quotation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuotationFactory> */
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): QuotationFactory
    {
        return QuotationFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_id',
        'quotation_number',
        'issue_date',
        'valid_until',
        'subtotal_cents',
        'discount_cents',
        'tax_rate_bps',
        'tax_cents',
        'total_cents',
        'status',
        'notes',
        'terms',
        'converted_order_id',
        'assigned_to',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'issue_date'       => 'date',
        'valid_until'      => 'date',
        'subtotal_cents'   => 'integer',
        'discount_cents'   => 'integer',
        'tax_rate_bps'     => 'integer',
        'tax_cents'        => 'integer',
        'total_cents'      => 'integer',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * All line items, ordered by sort_order for correct PDF rendering.
     *
     * @return HasMany<QuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    /**
     * Full audit trail of every status transition, oldest-first.
     *
     * @return HasMany<QuotationStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(QuotationStatusHistory::class)->oldest('created_at');
    }

    /**
     * The staff member responsible for following up on this quotation.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The staff member who originally created this quotation.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Order created when this quotation was accepted and converted.
     *
     * Null until QuotationService::accept(convertToOrder: true) has been called.
     *
     * @return BelongsTo<Order, $this>
     */
    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    /**
     * Whether this quotation is still in the draft state.
     *
     * Drafts can be freely edited; any other status locks the line items.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
