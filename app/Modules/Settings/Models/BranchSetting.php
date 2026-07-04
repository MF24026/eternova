<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-branch-aware key-value setting for tenant EXECUTION config.
 *
 * Storage (branch_settings):
 *   - branch_id = null  → tenant default
 *   - branch_id = <id>  → per-branch override (future; Sprint 8 UX writes defaults only)
 *
 * Read through the lenient resolver (resolvedGroup), never raw queries, so the
 * fallback chain coded-default ← tenant-default ← branch-override is honoured.
 *
 * The catalog of valid groups/keys + coded defaults lives in config/tenant-settings.php.
 * Writes go through writeDefault(), which allow-lists keys and handles the MySQL
 * NULL-distinct UNIQUE gotcha via updateOrCreate (SELECT-before-insert).
 */
final class BranchSetting extends Model
{
    use BelongsToTenant;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'group',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Coded defaults for a group from config/tenant-settings.php.
     *
     * @return array<string, mixed>
     */
    public static function codedDefaults(string $group): array
    {
        return config("tenant-settings.defaults.{$group}", []);
    }

    /**
     * Resolve a settings group for the current tenant (and optional branch) by
     * layering: coded defaults ← tenant default rows ← branch override rows.
     *
     * Returns the coded defaults verbatim when no tenant is resolved (CLI/tests
     * without a bound tenant) so callers never crash or leak across tenants.
     *
     * @return array<string, mixed>
     */
    public static function resolvedGroup(string $group, ?string $branchId = null): array
    {
        $resolved = self::codedDefaults($group);

        if (! app()->bound('currentTenant')) {
            return $resolved;
        }

        // Tenant default rows (branch_id = null). TenantScope filters by tenant.
        $defaults = self::query()
            ->where('group', $group)
            ->whereNull('branch_id')
            ->pluck('value', 'key')
            ->all();

        $resolved = array_merge($resolved, $defaults);

        // Per-branch overrides win when a branch is given (future; null today).
        if ($branchId !== null) {
            $overrides = self::query()
                ->where('group', $group)
                ->where('branch_id', $branchId)
                ->pluck('value', 'key')
                ->all();

            $resolved = array_merge($resolved, $overrides);
        }

        return $resolved;
    }

    /**
     * Upsert the TENANT DEFAULT rows (branch_id = null) for a group. Only keys
     * declared in the group's coded defaults are persisted — unknown keys are
     * silently dropped so the API can never write arbitrary settings.
     *
     * updateOrCreate SELECTs by (tenant, group, key, branch_id IS NULL) before
     * inserting, which sidesteps the MySQL NULL-distinct UNIQUE gotcha.
     *
     * @param  array<string, mixed>  $values
     */
    public static function writeDefault(string $group, array $values): void
    {
        $allowed = array_keys(self::codedDefaults($group));

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            self::updateOrCreate(
                ['group' => $group, 'key' => $key, 'branch_id' => null],
                ['value' => $value],
            );
        }
    }
}
