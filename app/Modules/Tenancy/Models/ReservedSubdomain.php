<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Database\Factories\ReservedSubdomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level model — intentionally NOT tenant-scoped.
 *
 * Reserved subdomains are a platform concern: they protect the SaaS brand, trademark
 * names, and sensitive terms from being claimed by any tenant during signup.
 */
class ReservedSubdomain extends Model
{
    /** @use HasFactory<ReservedSubdomainFactory> */
    use HasFactory;

    protected static function newFactory(): ReservedSubdomainFactory
    {
        return ReservedSubdomainFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subdomain',
        'category',
    ];
}
