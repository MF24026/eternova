<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Resolves and persists tenant settings across two storage backends:
 *
 *   - POLICY groups (brand, locale, quotations, reservations) live as explicit
 *     columns on the `tenants` row.
 *   - EXECUTION groups (contact, tax, orders, notifications) live in the
 *     per-branch-aware `branch_settings` store. Sprint 8 UX writes the tenant
 *     default (branch_id = null) via BranchSetting::writeDefault.
 *
 * The controller stays thin — all the group→backend routing lives here.
 */
final class SettingsService
{
    /** Execution groups stored in branch_settings (keys validated by config catalog). */
    private const BRANCH_GROUPS = ['contact', 'tax', 'orders', 'notifications'];

    /**
     * Tenant-row groups → the tenant columns they map to.
     *
     * @var array<string, list<string>>
     */
    private const TENANT_COLUMN_MAP = [
        'brand'        => ['business_name', 'primary_color', 'secondary_color'],
        'locale'       => ['currency', 'country_code', 'language', 'timezone'],
        'quotations'   => ['quotation_tax_rate_bps', 'quotation_valid_days', 'quotation_terms'],
        'reservations' => ['reservation_deposit_pct', 'reservation_occasions'],
    ];

    /**
     * All settings groups exposed by the module, in UI tab order.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return ['brand', 'locale', 'contact', 'tax', 'orders', 'quotations', 'reservations', 'notifications'];
    }

    public static function isKnownGroup(string $group): bool
    {
        return in_array($group, self::groups(), true);
    }

    /**
     * Resolve every settings group into a single nested array for the show endpoint.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolveAll(Tenant $tenant): array
    {
        return [
            'brand' => [
                'business_name'   => $tenant->business_name,
                'primary_color'   => $tenant->primary_color,
                'secondary_color' => $tenant->secondary_color,
                'logo_url'        => $tenant->logo_url,
                'favicon_url'     => $tenant->favicon_url,
            ],
            'locale' => [
                'currency'     => $tenant->currency,
                'country_code' => $tenant->country_code,
                'language'     => $tenant->language,
                'timezone'     => $tenant->timezone,
            ],
            'quotations' => [
                'quotation_tax_rate_bps' => (int) $tenant->quotation_tax_rate_bps,
                'quotation_valid_days'   => (int) $tenant->quotation_valid_days,
                'quotation_terms'        => $tenant->quotation_terms,
            ],
            'reservations' => [
                'reservation_deposit_pct' => (int) $tenant->reservation_deposit_pct,
                'reservation_occasions'   => $tenant->reservation_occasions ?? [],
            ],
            'contact'       => BranchSetting::resolvedGroup('contact'),
            'tax'           => BranchSetting::resolvedGroup('tax'),
            'orders'        => BranchSetting::resolvedGroup('orders'),
            'notifications' => BranchSetting::resolvedGroup('notifications'),
        ];
    }

    /**
     * Persist one settings group. Routes to the tenant row or branch_settings
     * depending on the group. Brand additionally handles logo/favicon uploads.
     *
     * @param  array<string, mixed>  $data   already-validated payload
     * @param  array<string, UploadedFile|null>  $files  optional 'logo' / 'favicon'
     */
    public function updateGroup(Tenant $tenant, string $group, array $data, array $files = []): void
    {
        if (! self::isKnownGroup($group)) {
            throw new InvalidArgumentException("Unknown settings group '{$group}'.");
        }

        if (in_array($group, self::BRANCH_GROUPS, true)) {
            BranchSetting::writeDefault($group, $data);

            return;
        }

        // Tenant-row group: pick only the mapped columns, then persist.
        $columns = self::TENANT_COLUMN_MAP[$group];
        $payload = array_intersect_key($data, array_flip($columns));

        if ($group === 'brand') {
            $payload = array_merge($payload, $this->handleBrandUploads($tenant, $files));
        }

        if ($payload !== []) {
            $tenant->forceFill($payload)->save();
        }
    }

    /**
     * Store uploaded logo/favicon to the public disk and return the columns to set.
     * Old files are deleted to avoid orphans.
     *
     * @param  array<string, UploadedFile|null>  $files
     * @return array<string, string>
     */
    private function handleBrandUploads(Tenant $tenant, array $files): array
    {
        $changes  = [];
        $diskName = config('tenant-settings.brand_disk', 'public');
        $disk     = Storage::disk($diskName);
        $dir      = "tenants/{$tenant->id}/brand";
        // url('') gives the disk's public base (APP_URL/storage for local,
        // the r2.dev/custom-domain origin for R2) — used to map a stored URL
        // back to its object key for deletion.
        $base = rtrim($disk->url(''), '/') . '/';

        foreach (['logo' => 'logo_url', 'favicon' => 'favicon_url'] as $field => $column) {
            $file = $files[$field] ?? null;

            if (! $file instanceof UploadedFile) {
                continue;
            }

            // Remove the previous asset if it lived on our brand disk.
            $previous = $tenant->{$column};
            if (is_string($previous) && $previous !== '') {
                if (str_starts_with($previous, $base)) {
                    $disk->delete(substr($previous, strlen($base)));
                } elseif (str_starts_with($previous, '/storage/')) {
                    // Legacy value stored before brand_disk was configurable.
                    $disk->delete(substr($previous, strlen('/storage/')));
                }
            }

            // S3/R2 disks need the absolute public URL; the local "public" disk
            // keeps its origin-portable /storage relative path. Either way the
            // stored value is used verbatim by every reader.
            $path = $file->store($dir, $diskName);
            $changes[$column] = config("filesystems.disks.{$diskName}.driver") === 's3'
                ? $disk->url($path)
                : '/storage/' . $path;
        }

        return $changes;
    }

    /**
     * Option lists for the Settings UI (countries, currencies, languages).
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return config('tenant-settings.catalog', []);
    }
}
