<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custom domains configured by tenants (e.g. rosaeterna.com via CNAME).
 *
 * This model is intentionally NOT tenant-scoped via BelongsToTenant.
 *
 * Reason: the domain verification + SSL renewal jobs run outside of any tenant HTTP
 * context. Super-admin also needs to see all domains across tenants for support.
 * Applying BelongsToTenant would silently filter queries in those contexts and would
 * require withoutGlobalScopes() everywhere — more dangerous than just keeping it plain.
 */
class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory;

    protected static function newFactory(): TenantDomainFactory
    {
        return TenantDomainFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'domain',
        'status',
        'verification_token',
        'verified_at',
        'ssl_status',
        'ssl_expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'verified_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
