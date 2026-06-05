<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTenantsSeeder;
use Database\Seeders\PlansSeeder;
use Database\Seeders\ReservedSubdomainsSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integrity tests for the demo data seeders.
 *
 * All tests use RefreshDatabase so each test starts from a clean schema.
 * No mocks — every assertion hits the real DB.
 */
final class DemoDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ SuperAdmin

    public function test_super_admin_seeder_creates_one_super_admin(): void
    {
        $this->seed(SuperAdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);

        $admin = User::where('is_super_admin', true)->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin@eternova.app', $admin->email);
        $this->assertTrue($admin->is_super_admin);
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_super_admin_seeder_is_idempotent(): void
    {
        $this->seed(SuperAdminUserSeeder::class);
        $this->seed(SuperAdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, User::where('is_super_admin', true)->count());
    }

    // ------------------------------------------------------------------ DemoTenants

    public function test_demo_tenants_seeder_creates_two_tenants_with_correct_owners(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        // The migration 2026_05_15_000006 unconditionally creates a "demo" tenant for
        // data-backfill purposes, so the total count is 3 (demo + rosa-eterna + tatiana).
        $this->assertSame(2, Tenant::whereIn('slug', ['rosa-eterna', 'tatiana'])->count());

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $this->assertNotNull($rosaEterna);

        $tatiana = Tenant::findBySlug('tatiana');
        $this->assertNotNull($tatiana);

        // Each tenant has exactly one owner
        $rosaOwners = $rosaEterna->users()->wherePivot('role', 'owner')->count();
        $this->assertSame(1, $rosaOwners, 'rosa-eterna should have exactly 1 owner');

        $tatianaOwners = $tatiana->users()->wherePivot('role', 'owner')->count();
        $this->assertSame(1, $tatianaOwners, 'tatiana should have exactly 1 owner');

        // Verify correct owner emails
        $rosaOwnerEmail = $rosaEterna->users()->wherePivot('role', 'owner')->first()?->email;
        $this->assertSame('caro@rosaeterna.com', $rosaOwnerEmail);

        $tatianaOwnerEmail = $tatiana->users()->wherePivot('role', 'owner')->first()?->email;
        $this->assertSame('tati@regalostatiana.com', $tatianaOwnerEmail);
    }

    public function test_demo_tenants_seeder_creates_correct_staff_counts(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // Rosa Eterna: 3 staff members
        $rosaStaff = $rosaEterna->users()->wherePivot('role', 'staff')->count();
        $this->assertSame(3, $rosaStaff, 'rosa-eterna should have 3 staff');

        // Tatiana: 1 staff + 1 admin
        $tatianaStaff = $tatiana->users()->wherePivot('role', 'staff')->count();
        $this->assertSame(1, $tatianaStaff, 'tatiana should have 1 staff');

        $tatianaAdmins = $tatiana->users()->wherePivot('role', 'admin')->count();
        $this->assertSame(1, $tatianaAdmins, 'tatiana should have 1 admin');
    }

    public function test_each_demo_tenant_has_a_main_branch(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // Each tenant has exactly one branch with slug=main and is_main=true
        $rosaBranch = Branch::where('tenant_id', $rosaEterna->id)
            ->where('slug', 'main')
            ->where('is_main', true)
            ->first();

        $this->assertNotNull($rosaBranch, 'rosa-eterna should have a main branch');
        $this->assertSame(1, $rosaEterna->branches()->count());

        $tatianaBranch = Branch::where('tenant_id', $tatiana->id)
            ->where('slug', 'main')
            ->where('is_main', true)
            ->first();

        $this->assertNotNull($tatianaBranch, 'tatiana should have a main branch');
        $this->assertSame(1, $tatiana->branches()->count());
    }

    public function test_each_demo_tenant_has_a_trialing_subscription_on_correct_plan(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        $rosaSub = Subscription::where('tenant_id', $rosaEterna->id)->first();
        $this->assertNotNull($rosaSub, 'rosa-eterna should have a subscription');
        $this->assertSame('trialing', $rosaSub->status);
        $this->assertNotNull($rosaSub->trial_ends_at);
        $this->assertTrue($rosaSub->trial_ends_at->isFuture(), 'rosa-eterna trial_ends_at should be in the future');
        $this->assertSame('pro', $rosaSub->plan->slug);

        $tatianaSub = Subscription::where('tenant_id', $tatiana->id)->first();
        $this->assertNotNull($tatianaSub, 'tatiana should have a subscription');
        $this->assertSame('trialing', $tatianaSub->status);
        $this->assertNotNull($tatianaSub->trial_ends_at);
        $this->assertTrue($tatianaSub->trial_ends_at->isFuture(), 'tatiana trial_ends_at should be in the future');
        $this->assertSame('basico', $tatianaSub->plan->slug);
    }

    // ------------------------------------------------------------------ Idempotency

    public function test_seeders_are_idempotent_when_run_twice(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(SuperAdminUserSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        // Run the same seeders a second time — counts must not double
        $this->seed(PlansSeeder::class);
        $this->seed(SuperAdminUserSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        // demo tenant (from migration) + rosa-eterna + tatiana = 3 total
        $this->assertSame(2, Tenant::whereIn('slug', ['rosa-eterna', 'tatiana'])->count());
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('branches', 2);

        // 1 super-admin + 2 owners + 5 staff = 8
        $this->assertDatabaseCount('users', 8);

        // The tenant_users pivot should not have duplicates (2 owners + 5 staff = 7 rows)
        $this->assertSame(7, \DB::table('tenant_users')->count());
    }

    // ------------------------------------------------------------------ Full pipeline

    public function test_database_seeder_runs_all_4_seeders_in_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        // ReservedSubdomainsSeeder: 86 entries
        $this->assertDatabaseCount('reserved_subdomains', 86);

        // PlansSeeder: 3 plans
        $this->assertDatabaseCount('plans', 3);

        // SuperAdminUserSeeder (1) + 2 owners + 5 staff = 8 users
        $this->assertDatabaseCount('users', 8);

        // DemoTenantsSeeder: 2 demo tenants (plus the "demo" tenant from migration backfill = 3 total)
        $this->assertSame(2, Tenant::whereIn('slug', ['rosa-eterna', 'tatiana'])->count());

        // 2 subscriptions (one per tenant)
        $this->assertDatabaseCount('subscriptions', 2);

        // 2 branches (one main branch per tenant)
        $this->assertDatabaseCount('branches', 2);
    }
}
