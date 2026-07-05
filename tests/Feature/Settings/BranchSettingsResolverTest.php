<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the per-branch-aware settings resolver (S8-E1).
 *
 * Verifies the lenient fallback chain (coded default ← tenant default ← branch
 * override), multi-tenant + per-branch isolation, the write allow-list, and the
 * MySQL NULL-distinct UNIQUE handling via updateOrCreate.
 */
final class BranchSettingsResolverTest extends TestCase
{
    use RefreshDatabase;

    private function bindTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public function test_it_returns_coded_defaults_on_a_fresh_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->bindTenant($tenant);

        $tax = BranchSetting::resolvedGroup('tax');

        // No rows yet → coded defaults from config/tenant-settings.php.
        // Tax ships OFF by default (conservative rollout — each tenant opts in).
        $this->assertSame(1300, $tax['rate_bps']);
        $this->assertFalse($tax['enabled']);
        $this->assertNull($tax['id_label']);
    }

    public function test_tenant_default_row_overrides_the_coded_default(): void
    {
        $tenant = Tenant::factory()->create();
        $this->bindTenant($tenant);

        BranchSetting::writeDefault('tax', ['rate_bps' => 1600, 'enabled' => false]);

        $tax = BranchSetting::resolvedGroup('tax');

        $this->assertSame(1600, $tax['rate_bps']);
        $this->assertFalse($tax['enabled']);
        // Untouched keys still fall back to coded defaults.
        $this->assertNull($tax['id_number']);
    }

    public function test_branch_override_wins_over_tenant_default(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();
        $this->bindTenant($tenant);

        // Tenant default
        BranchSetting::writeDefault('contact', ['phone' => '+503 0000-0000']);

        // Branch override (written directly — Sprint 8 UX doesn't do this yet,
        // but the resolver must already honour it).
        BranchSetting::create([
            'branch_id' => $branch->id,
            'group' => 'contact',
            'key' => 'phone',
            'value' => '+503 7777-7777',
        ]);

        $default = BranchSetting::resolvedGroup('contact');
        $scoped = BranchSetting::resolvedGroup('contact', $branch->id);

        $this->assertSame('+503 0000-0000', $default['phone']);
        $this->assertSame('+503 7777-7777', $scoped['phone']);
    }

    public function test_writes_are_isolated_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->bindTenant($tenantA);
        BranchSetting::writeDefault('tax', ['rate_bps' => 1900]);

        // Tenant B sees its own coded default, not A's row.
        $this->bindTenant($tenantB);
        $taxB = BranchSetting::resolvedGroup('tax');

        $this->assertSame(1300, $taxB['rate_bps']);

        // And A still sees its own value.
        $this->bindTenant($tenantA);
        $this->assertSame(1900, BranchSetting::resolvedGroup('tax')['rate_bps']);
    }

    public function test_write_default_ignores_keys_not_in_the_catalog(): void
    {
        $tenant = Tenant::factory()->create();
        $this->bindTenant($tenant);

        BranchSetting::writeDefault('tax', [
            'rate_bps' => 1000,
            'evil_key' => 'nope',
            'is_admin' => true,
        ]);

        $this->assertDatabaseHas('branch_settings', [
            'tenant_id' => $tenant->id,
            'group' => 'tax',
            'key' => 'rate_bps',
        ]);
        $this->assertDatabaseMissing('branch_settings', [
            'tenant_id' => $tenant->id,
            'group' => 'tax',
            'key' => 'evil_key',
        ]);
        $this->assertDatabaseMissing('branch_settings', [
            'tenant_id' => $tenant->id,
            'group' => 'tax',
            'key' => 'is_admin',
        ]);
    }

    public function test_write_default_upserts_without_duplicating_the_default_row(): void
    {
        $tenant = Tenant::factory()->create();
        $this->bindTenant($tenant);

        BranchSetting::writeDefault('contact', ['email' => 'first@shop.test']);
        BranchSetting::writeDefault('contact', ['email' => 'second@shop.test']);

        // Exactly one default row for (tenant, contact, email) — NULL-distinct gotcha handled.
        $this->assertSame(
            1,
            BranchSetting::query()
                ->where('group', 'contact')
                ->where('key', 'email')
                ->whereNull('branch_id')
                ->count(),
        );
        $this->assertSame('second@shop.test', BranchSetting::resolvedGroup('contact')['email']);
    }

    public function test_resolved_group_returns_coded_defaults_without_a_bound_tenant(): void
    {
        // No currentTenant bound → resolver must not crash or leak; returns coded defaults.
        app()->forgetInstance('currentTenant');

        $notifications = BranchSetting::resolvedGroup('notifications');

        $this->assertTrue($notifications['new_order']);
        $this->assertFalse($notifications['reservation_confirmed']);
    }

    public function test_overrides_are_isolated_per_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $branchA = Branch::factory()->forTenant($tenant)->create();
        $branchB = Branch::factory()->forTenant($tenant)->create();
        $this->bindTenant($tenant);

        BranchSetting::writeDefault('contact', ['phone' => 'default']);
        BranchSetting::create([
            'branch_id' => $branchA->id,
            'group' => 'contact',
            'key' => 'phone',
            'value' => 'branch-A-only',
        ]);

        $this->assertSame('branch-A-only', BranchSetting::resolvedGroup('contact', $branchA->id)['phone']);
        // Branch B has no override → falls back to the tenant default.
        $this->assertSame('default', BranchSetting::resolvedGroup('contact', $branchB->id)['phone']);
    }
}
