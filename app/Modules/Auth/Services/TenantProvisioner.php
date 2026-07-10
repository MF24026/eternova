<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Catalog\Services\StarterCatalogService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provisions a new tenant for an existing user in a single atomic transaction.
 *
 * Creates:
 *   1. Tenant row (identity + brand + locale config)
 *   2. Branch "Main" — the default branch every tenant starts with
 *   3. Subscription (trialing, 30 days, on the requested plan)
 *   4. tenant_users row — the owner link between user and tenant
 *
 * All four operations happen inside DB::transaction(). If any step fails, none
 * of the rows are persisted. This keeps the DB in a consistent state even when
 * a seeder, test, or concurrent request causes a mid-way failure.
 */
final readonly class TenantProvisioner
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private StarterCatalogService $starterCatalog,
    ) {}

    /**
     * Provision a complete tenant for the given owner.
     *
     * @param  array{slug: string, name: string, business_name: string, country_code: string, currency: string, language: string, timezone: string, plan_slug?: string}  $tenantData
     *
     * @throws \DomainException when the requested plan slug does not exist
     */
    public function provision(User $owner, array $tenantData): Tenant
    {
        $planSlug = $tenantData['plan_slug'] ?? 'basico';

        $plan = Plan::where('slug', $planSlug)->where('is_active', true)->first();

        if ($plan === null) {
            throw new \DomainException(
                "Cannot provision tenant: plan '{$planSlug}' does not exist or is not active. ".
                'Pass a valid plan_slug or omit it to use the default plan (basico).'
            );
        }

        $tenant = DB::transaction(function () use ($owner, $tenantData, $plan): Tenant {
            // Step 1 — Tenant
            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'slug' => $tenantData['slug'],
                'email' => $owner->email,
                'status' => 'active',
                'business_name' => $tenantData['business_name'],
                'business_type' => $tenantData['business_type'] ?? 'otro',
                'country_code' => $tenantData['country_code'],
                'currency' => $tenantData['currency'],
                'language' => $tenantData['language'],
                'timezone' => $tenantData['timezone'],
                'trial_ends_at' => now()->addDays(30),
            ]);

            // Step 2 — Branch principal
            Branch::create([
                'tenant_id' => $tenant->id,
                'name' => $tenantData['name'],
                'slug' => 'main',
                'is_main' => true,
                'is_active' => true,
            ]);

            // Step 3 — Subscription (trialing)
            $this->subscriptionService->create($tenant, $plan);

            // Step 4 — Owner link
            $tenant->users()->attach($owner->id, [
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            Log::info('Tenant provisioned', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'owner_id' => $owner->id,
                'plan' => $plan->slug,
            ]);

            return $tenant;
        });

        // Seed a starter catalog so the owner sees real data on first login.
        // Done after the provisioning transaction commits and isolated in its own
        // try/catch: a catalog-seeding hiccup must never fail tenant registration.
        // The starter template is derived from the tenant's giro (single source of
        // truth: config/verticals.php). A giro with a null template seeds nothing —
        // do NOT fall back to a default catalog for an unrelated business.
        $starterTemplate = config("verticals.catalog.{$tenant->business_type}.starter_template");

        if ($starterTemplate !== null) {
            try {
                $this->starterCatalog->seed($tenant, $starterTemplate);
            } catch (\Throwable $e) {
                Log::warning('Starter catalog seeding failed', [
                    'tenant_id' => $tenant->id,
                    'template' => $starterTemplate,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $tenant;
    }
}
