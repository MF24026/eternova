# Business Verticals Framework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a tenant pick a business type (giro) at signup so the software hides modules that do not apply to that giro, with per-tenant overrides — an extensible catalog where adding a giro is a config entry.

**Architecture:** `business_type` + `module_overrides` on the tenant; a `config/verticals.php` catalog is the single source of truth; a central `ModuleVisibilityService` composes giro defaults with overrides; enforcement is server-side (a middleware 403s disabled modules) and in the nav (from `/me`). Business modules stay ignorant of verticals.

**Tech Stack:** Laravel 12 (PHP 8.4), Vue 3 SPA (vue-router + Pinia + axios, NOT Inertia), MySQL, Pest/PHPUnit, Playwright.

## Global Constraints

- No emojis anywhere — code, comments, commits, identifiers, UI. Lucide icons only. No AI co-author trailer. Conventional Commits.
- Feature branch `feat/business-verticals` → PR against `develop`; squash-merge.
- Multi-tenant: `business_type` / `module_overrides` are per-tenant; never leak across tenants.
- Modules stay ignorant of verticals; one resolver (`ModuleVisibilityService`), one catalog (`config/verticals.php`).
- Vertical-gating HIDES a module (not applicable to giro); plan-gating LOCKS (upsell). Do not conflate — do not add UpgradeLock to giro-gated modules.
- Enforcement is server-side too: a disabled module 403s on the API, not just hidden in nav.
- `business_type` enum cases: `floreria_regalos`, `ropa_boutique`, `accesorios`, `minimarket`, `otro`. Default `otro`.
- Gateable optional modules (this phase): `reservations`, `quotations`. Core modules are never gated.
- Eloquent does not populate a DB column default in-memory after `create()` → mirror defaults in `protected $attributes`.
- Testing DB is slow; run tests in the background and poll. If migrate errors "table already exists", drop+recreate `eternova_testing`. e2e runs against the built bundle — `npm run build` + `artisan optimize:clear` before Playwright.

---

### Task 1: `business_type` column, enum, model default

**Files:**
- Create: `database/migrations/2026_07_10_000001_add_business_type_to_tenants.php`
- Create: `app/Modules/Tenancy/Enums/BusinessType.php`
- Modify: `app/Modules/Tenancy/Models/Tenant.php`
- Test: `tests/Feature/Tenancy/BusinessTypeTest.php`

**Interfaces:**
- Produces: `BusinessType` string enum (`Floreria = 'floreria_regalos'`, `Ropa = 'ropa_boutique'`, `Accesorios = 'accesorios'`, `Minimarket = 'minimarket'`, `Otro = 'otro'`); `tenants.business_type` default `'otro'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tenant_defaults_to_otro(): void
    {
        $this->assertSame('otro', Tenant::factory()->create()->business_type);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `./vendor/bin/sail artisan test --filter=BusinessTypeTest` → FAIL (no column).

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('business_type', 40)->default('otro')->after('admin_theme');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('business_type');
        });
    }
};
```

- [ ] **Step 4: Enum**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum BusinessType: string
{
    case Floreria = 'floreria_regalos';
    case Ropa = 'ropa_boutique';
    case Accesorios = 'accesorios';
    case Minimarket = 'minimarket';
    case Otro = 'otro';
}
```

- [ ] **Step 5: Model** — in `Tenant.php` add `'business_type',` to `$fillable` (after `'admin_theme',`) and, in the existing `protected $attributes` array, add `'business_type' => 'otro',`.

- [ ] **Step 6: Run** — `./vendor/bin/sail artisan migrate --force && ./vendor/bin/sail artisan test --filter=BusinessTypeTest` → PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_10_000001_add_business_type_to_tenants.php app/Modules/Tenancy/Enums/BusinessType.php app/Modules/Tenancy/Models/Tenant.php tests/Feature/Tenancy/BusinessTypeTest.php
git commit -m "feat(tenancy): add business_type column, enum and default"
```

---

### Task 2: Vertical catalog config + `module_overrides` column + `ModuleVisibilityService`

**Files:**
- Create: `config/verticals.php`
- Create: `database/migrations/2026_07_10_000002_add_module_overrides_to_tenants.php`
- Create: `app/Modules/Tenancy/Services/ModuleVisibilityService.php`
- Modify: `app/Modules/Tenancy/Models/Tenant.php` (fillable + cast for `module_overrides`)
- Test: `tests/Feature/Tenancy/ModuleVisibilityTest.php`

**Interfaces:**
- Consumes: `BusinessType` (Task 1).
- Produces: `ModuleVisibilityService::GATEABLE = ['reservations', 'quotations']`;
  `enabledModules(Tenant): array<string>` (subset of GATEABLE); `isEnabled(Tenant, string $module): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\ModuleVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ModuleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private ModuleVisibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ModuleVisibilityService::class);
    }

    public function test_floreria_enables_reservations_and_quotations(): void
    {
        $t = Tenant::factory()->create(['business_type' => 'floreria_regalos']);
        $this->assertEqualsCanonicalizing(['reservations', 'quotations'], $this->service->enabledModules($t));
    }

    public function test_ropa_enables_no_optional_modules(): void
    {
        $t = Tenant::factory()->create(['business_type' => 'ropa_boutique']);
        $this->assertSame([], $this->service->enabledModules($t));
    }

    public function test_override_flips_a_module_regardless_of_giro(): void
    {
        $t = Tenant::factory()->create(['business_type' => 'floreria_regalos', 'module_overrides' => ['quotations' => false]]);
        $this->assertSame(['reservations'], array_values($this->service->enabledModules($t)));

        $t2 = Tenant::factory()->create(['business_type' => 'ropa_boutique', 'module_overrides' => ['reservations' => true]]);
        $this->assertSame(['reservations'], array_values($this->service->enabledModules($t2)));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — FAIL (service/column absent).

- [ ] **Step 3: Catalog config `config/verticals.php`**

```php
<?php

declare(strict_types=1);

/*
 * Single source of truth for business verticals (giros). Adding a giro is one entry
 * here plus a case on App\Modules\Tenancy\Enums\BusinessType. `modules` lists the
 * optional (gateable) modules that are ON by default for that giro; core modules are
 * never listed here. `icon` is a Lucide icon name for the onboarding picker.
 */
return [
    'catalog' => [
        'floreria_regalos' => ['label' => 'Florería / Regalos', 'icon' => 'flower', 'modules' => ['reservations', 'quotations']],
        'ropa_boutique' => ['label' => 'Ropa / Boutique', 'icon' => 'shirt', 'modules' => []],
        'accesorios' => ['label' => 'Accesorios / Maquillaje', 'icon' => 'gem', 'modules' => []],
        'minimarket' => ['label' => 'Minimarket / Abarrotes', 'icon' => 'shopping-basket', 'modules' => []],
        'otro' => ['label' => 'Otro', 'icon' => 'store', 'modules' => ['reservations', 'quotations']],
    ],
];
```

- [ ] **Step 4: Migration for `module_overrides`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('module_overrides')->nullable()->after('business_type');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('module_overrides');
        });
    }
};
```

- [ ] **Step 5: Model** — in `Tenant.php` add `'module_overrides',` to `$fillable` and `'module_overrides' => 'array',` to the `casts()` array (or `$casts`).

- [ ] **Step 6: Service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Resolves which OPTIONAL (gateable) modules a tenant sees, composing the giro's
 * catalog defaults with the tenant's explicit per-module overrides. This is the ONLY
 * place that answers module visibility for verticals; business modules stay ignorant
 * of it. Plan-gating (usePlanGate) is a separate concern and not touched here.
 */
final class ModuleVisibilityService
{
    /** @var list<string> */
    public const GATEABLE = ['reservations', 'quotations'];

    /**
     * @return list<string> enabled gateable modules for the tenant
     */
    public function enabledModules(Tenant $tenant): array
    {
        $catalog = (array) config('verticals.catalog', []);
        $defaults = (array) ($catalog[$tenant->business_type]['modules'] ?? []);
        $overrides = (array) ($tenant->module_overrides ?? []);

        return array_values(array_filter(self::GATEABLE, static function (string $module) use ($defaults, $overrides): bool {
            return array_key_exists($module, $overrides)
                ? (bool) $overrides[$module]
                : in_array($module, $defaults, true);
        }));
    }

    public function isEnabled(Tenant $tenant, string $module): bool
    {
        // A non-gateable (core) module is always enabled.
        if (! in_array($module, self::GATEABLE, true)) {
            return true;
        }

        return in_array($module, $this->enabledModules($tenant), true);
    }
}
```

- [ ] **Step 7: Run** — `migrate --force` then `--filter=ModuleVisibilityTest` → PASS.

- [ ] **Step 8: Commit**

```bash
git add config/verticals.php database/migrations/2026_07_10_000002_add_module_overrides_to_tenants.php app/Modules/Tenancy/Services/ModuleVisibilityService.php app/Modules/Tenancy/Models/Tenant.php tests/Feature/Tenancy/ModuleVisibilityTest.php
git commit -m "feat(tenancy): vertical catalog + module_overrides + ModuleVisibilityService"
```

---

### Task 3: Capture `business_type` at signup

**Files:**
- Modify: `app/Modules/Auth/Http/Requests/CreateTenantRequest.php`
- Modify: `app/Modules/Auth/Services/TenantProvisioner.php`
- Test: `tests/Feature/Tenancy/BusinessTypeTest.php` (extend)

**Interfaces:**
- Consumes: `BusinessType` (Task 1).
- Produces: `POST /api/v1/auth/register` accepts `business_type`; the created tenant persists it (defaults to `otro` when absent).

- [ ] **Step 1: Write the failing test (append to BusinessTypeTest)**

Study `RegisterController` + `TenantProvisioner` for the register payload shape first, then:

```php
    public function test_register_persists_business_type(): void
    {
        $payload = [
            // Copy the minimal valid register payload from an existing auth/onboarding
            // test (account + tenant fields), then set business_type.
            'business_type' => 'ropa_boutique',
        ] + $this->validRegisterPayload();

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        $this->assertSame('ropa_boutique', \App\Modules\Tenancy\Models\Tenant::latest('id')->first()->business_type);
    }
```

Add a `validRegisterPayload(): array` helper mirroring the existing passing register test in `tests/Feature/` (find it with `grep -rl "auth/register" tests/Feature`). Reuse its exact field set so the request validates.

- [ ] **Step 2: Run to verify it fails** — FAIL (business_type not persisted).

- [ ] **Step 3: Validate** — in `CreateTenantRequest::rules()`, add:

```php
'business_type' => ['sometimes', \Illuminate\Validation\Rule::enum(\App\Modules\Tenancy\Enums\BusinessType::class)],
```

- [ ] **Step 4: Persist** — in `TenantProvisioner` where `Tenant::create([...])` is called (around line 58), add to the array:

```php
'business_type' => $tenantData['business_type'] ?? 'otro',
```

Confirm `$tenantData` is the validated tenant sub-array; if the provisioner receives the whole request differently, pass `business_type` through the same channel `business_name` travels.

- [ ] **Step 5: Run** → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Auth/Http/Requests/CreateTenantRequest.php app/Modules/Auth/Services/TenantProvisioner.php tests/Feature/Tenancy/BusinessTypeTest.php
git commit -m "feat(auth): capture business_type at tenant signup"
```

---

### Task 4: Expose `business_type` + `enabled_modules` at bootstrap (`/me`)

**Files:**
- Modify: `app/Modules/Auth/Http/Resources/UserResource.php`
- Test: `tests/Feature/Tenancy/ModuleVisibilityTest.php` (extend)

**Interfaces:**
- Consumes: `ModuleVisibilityService` (Task 2).
- Produces: `/me` tenant block carries `business_type` and `enabled_modules: string[]`.

- [ ] **Step 1: Write the failing test (append)** — uses the `ActingAsTenantMember` trait + `tenantUrl` (see `tests/Feature/Settings/AdminThemeTest.php` for the exact helper pattern):

```php
    public function test_me_exposes_business_type_and_enabled_modules(): void
    {
        // Build an owner + tenant (floreria) via the ActingAsTenantMember helper used
        // in AdminThemeTest, then:
        $response = $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.tenants.0.business_type', 'floreria_regalos');

        $this->assertEqualsCanonicalizing(
            ['reservations', 'quotations'],
            $response->json('data.tenants.0.enabled_modules'),
        );
    }
```

(Bring in `use ActingAsTenantMember;` and the `ownerForTenant()` helper from AdminThemeTest.)

- [ ] **Step 2: Run to verify it fails** — FAIL (fields absent).

- [ ] **Step 3: Expose** — in `UserResource`'s tenant map closure, add after `'business_name'`:

```php
'business_type' => $tenant->business_type,
'enabled_modules' => app(\App\Modules\Tenancy\Services\ModuleVisibilityService::class)->enabledModules($tenant),
```

- [ ] **Step 4: Run** → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Auth/Http/Resources/UserResource.php tests/Feature/Tenancy/ModuleVisibilityTest.php
git commit -m "feat(auth): expose business_type and enabled_modules in /me"
```

---

### Task 5: Server-side enforcement — `EnsureModuleEnabled` middleware

**Files:**
- Create: `app/Modules/Tenancy/Http/Middleware/EnsureModuleEnabled.php`
- Modify: `bootstrap/app.php` (register the alias)
- Modify: `routes/api/v1/reservations.php`, `routes/api/v1/quotations.php` (apply middleware)
- Test: `tests/Feature/Tenancy/ModuleGateTest.php`

**Interfaces:**
- Consumes: `ModuleVisibilityService` (Task 2), the `tenant` middleware (sets `current_tenant()`).
- Produces: middleware alias `module:{name}`; disabled module → 403, enabled → passes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class ModuleGateTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    public function test_disabled_module_returns_403_on_api(): void
    {
        $tenant = Tenant::factory()->create(['business_type' => 'ropa_boutique']); // quotations OFF
        app()->instance('currentTenant', $tenant);
        $owner = \App\Models\User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/quotations'))
            ->assertStatus(403);
    }

    public function test_enabled_module_is_reachable(): void
    {
        $tenant = Tenant::factory()->create(['business_type' => 'floreria_regalos']); // quotations ON
        app()->instance('currentTenant', $tenant);
        $owner = \App\Models\User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/quotations'))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run to verify it fails** — FAIL (both reachable today).

- [ ] **Step 3: Middleware**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Services\ModuleVisibilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request when the current tenant has the named optional module disabled
 * for its business vertical. Runs after the `tenant` middleware, so current_tenant()
 * is resolved. Server-side twin of the nav gating — a disabled module is unreachable
 * via the API, not merely hidden.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleVisibilityService $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = current_tenant();

        if ($tenant !== null && ! $this->modules->isEnabled($tenant, $module)) {
            abort(403, "El módulo '{$module}' no está habilitado para este negocio.");
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register alias** — in `bootstrap/app.php`'s `->withMiddleware(...)`, add to the alias map:

```php
$middleware->alias(['module' => \App\Modules\Tenancy\Http\Middleware\EnsureModuleEnabled::class]);
```

(Merge into the existing `alias([...])` call if present — check how `tenant`/`super_admin` aliases are registered and follow that exact form.)

- [ ] **Step 5: Apply to routes** — in `routes/api/v1/quotations.php`, add `'module:quotations'` to the group middleware array (`['auth:sanctum', 'tenant', 'module:quotations']`). Same for `routes/api/v1/reservations.php` with `'module:reservations'`.

- [ ] **Step 6: Run** — `optimize:clear` then `--filter=ModuleGateTest` → PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Tenancy/Http/Middleware/EnsureModuleEnabled.php bootstrap/app.php routes/api/v1/quotations.php routes/api/v1/reservations.php tests/Feature/Tenancy/ModuleGateTest.php
git commit -m "feat(tenancy): EnsureModuleEnabled middleware gates disabled modules server-side"
```

---

### Task 6: Settings — `modules` group (read resolved, write overrides)

**Files:**
- Modify: `app/Modules/Settings/Services/SettingsService.php` (groups, resolveAll, updateGroup)
- Modify: `app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php` (rules for `modules`)
- Test: `tests/Feature/Settings/ModuleSettingsTest.php`

**Interfaces:**
- Consumes: `ModuleVisibilityService` (Task 2).
- Produces: `GET /api/v1/settings` → `modules: { reservations: bool, quotations: bool }` (effective); `POST /api/v1/settings/modules` persists overrides on `tenants.module_overrides`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class ModuleSettingsTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private function owner(string $giro): array
    {
        $tenant = Tenant::factory()->create(['business_type' => $giro]);
        app()->instance('currentTenant', $tenant);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        return [$owner, $tenant];
    }

    public function test_show_returns_effective_module_flags(): void
    {
        [$owner, $tenant] = $this->owner('floreria_regalos');

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/settings'))
            ->assertOk()
            ->assertJsonPath('data.modules.reservations', true)
            ->assertJsonPath('data.modules.quotations', true);
    }

    public function test_owner_can_override_a_module(): void
    {
        [$owner, $tenant] = $this->owner('floreria_regalos');

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/settings/modules'), ['quotations' => false])
            ->assertOk();

        $this->assertFalse((bool) ($tenant->fresh()->module_overrides['quotations'] ?? true));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — FAIL (unknown group `modules`).

- [ ] **Step 3: SettingsService** — three edits:
  - Add `'modules'` to the array returned by `groups()`.
  - In `resolveAll()`, add a `'modules'` entry built from the resolver:
    ```php
    'modules' => collect(\App\Modules\Tenancy\Services\ModuleVisibilityService::GATEABLE)
        ->mapWithKeys(fn (string $m): array => [$m => app(\App\Modules\Tenancy\Services\ModuleVisibilityService::class)->isEnabled($tenant, $m)])
        ->all(),
    ```
  - In `updateGroup()`, before the `TENANT_COLUMN_MAP` lookup, special-case `modules` (it is not a column-mapped group):
    ```php
    if ($group === 'modules') {
        $overrides = (array) ($tenant->module_overrides ?? []);
        foreach (\App\Modules\Tenancy\Services\ModuleVisibilityService::GATEABLE as $m) {
            if (array_key_exists($m, $data)) {
                $overrides[$m] = (bool) $data[$m];
            }
        }
        $tenant->forceFill(['module_overrides' => $overrides])->save();

        return;
    }
    ```

- [ ] **Step 4: UpdateSettingsRequest** — add a `'modules'` arm to the `rules()` match:

```php
'modules' => [
    'reservations' => ['sometimes', 'boolean'],
    'quotations' => ['sometimes', 'boolean'],
],
```

- [ ] **Step 5: Run** → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Settings/Services/SettingsService.php app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php tests/Feature/Settings/ModuleSettingsTest.php
git commit -m "feat(settings): modules group reads effective flags and writes overrides"
```

---

### Task 7: Frontend — types + nav gating + route guards

**Files:**
- Modify: `resources/js/types/domain/User.ts` (tenant membership fields)
- Modify: `resources/js/components/layout/AdminLayout.vue` (navItems `module` + filter)
- Modify: `resources/js/router/guards.ts` (redirect gated routes)

**Interfaces:**
- Consumes: `/me` `enabled_modules` (Task 4).
- Produces: nav hides gated modules; direct navigation to a disabled module route redirects to `/admin/dashboard`.

- [ ] **Step 1: Types** — in `resources/js/types/domain/User.ts`, on `UserTenantMembership` add:

```ts
    business_type: 'floreria_regalos' | 'ropa_boutique' | 'accesorios' | 'minimarket' | 'otro'
    enabled_modules: string[]
```

- [ ] **Step 2: Nav gating** — in `AdminLayout.vue`:
  - Give the Reservas and Cotizaciones `navItems` entries a `module` id: add `module: 'reservations'` to the Reservas item and `module: 'quotations'` to the Cotizaciones item; add `module?: string` to the `NavItem` type.
  - Extend `visibleNavItems` so a gated item shows only when its module is enabled:
    ```ts
    const enabledModules = computed(() => currentUser.value?.tenants.find((t) => t.is_current)?.enabled_modules ?? [])
    const visibleNavItems = computed(() =>
        navItems.filter((item) =>
            (!item.ownerOnly || isOwner.value) &&
            (!item.module || enabledModules.value.includes(item.module)),
        ),
    )
    ```

- [ ] **Step 3: Route guards** — in `resources/js/router/guards.ts`, add a check: for routes whose path starts with `/admin/reservations` or `/admin/quotations`, if the corresponding module is not in the current tenant's `enabled_modules`, redirect to `/admin/dashboard`. Follow the existing guard structure (the file already gates e.g. `requiresSuperAdmin` / owner routes — mirror that shape and read the tenant from the same auth store it already uses).

- [ ] **Step 4: Typecheck + build** — `./vendor/bin/sail npx vue-tsc --noEmit && ./vendor/bin/sail npm run build` → clean.

- [ ] **Step 5: Commit**

```bash
git add resources/js/types/domain/User.ts resources/js/components/layout/AdminLayout.vue resources/js/router/guards.ts
git commit -m "feat(ui): hide giro-disabled modules in nav and guard their routes"
```

---

### Task 8: Frontend — onboarding giro picker

**Files:**
- Create: `resources/js/constants/verticals.ts`
- Modify: `resources/js/pages/Onboarding/TenantStep.vue` (giro picker)
- Modify: the onboarding submit path so `business_type` is sent to `POST /api/v1/auth/register` (find where TenantStep's data is collected — likely `SignupWizardPage.vue`).

**Interfaces:**
- Consumes: `POST /api/v1/auth/register` `business_type` (Task 3).
- Produces: the signup wizard sends the chosen `business_type`.

- [ ] **Step 1: Frontend catalog mirror** — `resources/js/constants/verticals.ts` (labels + Lucide icon components for the picker; the backend `config/verticals.php` remains the source of truth for module resolution — this is only the 5 static picker entries):

```ts
import { Flower, Shirt, Gem, ShoppingBasket, Store, type LucideIcon } from 'lucide-vue-next'

export type BusinessType = 'floreria_regalos' | 'ropa_boutique' | 'accesorios' | 'minimarket' | 'otro'

export const VERTICALS: Array<{ key: BusinessType; label: string; icon: LucideIcon }> = [
    { key: 'floreria_regalos', label: 'Florería / Regalos', icon: Flower },
    { key: 'ropa_boutique', label: 'Ropa / Boutique', icon: Shirt },
    { key: 'accesorios', label: 'Accesorios / Maquillaje', icon: Gem },
    { key: 'minimarket', label: 'Minimarket / Abarrotes', icon: ShoppingBasket },
    { key: 'otro', label: 'Otro', icon: Store },
]
```

- [ ] **Step 2: Picker in TenantStep** — add a grid of selectable cards (one per `VERTICALS` entry, icon + label), bound to a `business_type` field (default `'otro'`), each `data-testid="giro-{key}"`. Use design-system tokens (No-Line, `--r-lg`, primary ring on the selected card). Read the existing TenantStep to match how its fields (business_name) are modeled and emitted to the wizard.

- [ ] **Step 3: Send it** — ensure the wizard includes `business_type` in the register payload alongside `business_name`. Trace from TenantStep → SignupWizardPage → the register request; add the field to the payload object.

- [ ] **Step 4: Typecheck + build** → clean.

- [ ] **Step 5: Commit**

```bash
git add resources/js/constants/verticals.ts resources/js/pages/Onboarding/TenantStep.vue resources/js/pages/Onboarding/SignupWizardPage.vue
git commit -m "feat(onboarding): business type picker sends business_type at signup"
```

---

### Task 9: Frontend — Settings "Módulos" tab

**Files:**
- Modify: `resources/js/pages/Admin/SettingsPage.vue` (Módulos tab)
- Modify: `resources/js/types/domain/Settings.ts` (modules group type + group union)

**Interfaces:**
- Consumes: `GET/POST /api/v1/settings` `modules` group (Task 6).
- Produces: a tab with a toggle per gateable module; saving persists overrides; the nav updates on next `/me`.

- [ ] **Step 1: Type** — in `Settings.ts` add `'modules'` to the `SettingsGroup` union and:

```ts
export interface ModuleSettings {
    reservations: boolean
    quotations: boolean
}
```
and add `modules: ModuleSettings` to the aggregate settings interface.

- [ ] **Step 2: Tab** — add a "Módulos" tab to `SettingsPage.vue` (mirror an existing simple tab). Two toggle rows (Reservas, Cotizaciones) bound to `settings.modules.*`, with copy: "Tu giro define qué módulos ves; podés ajustarlos acá." Save via the existing per-group save flow (`save('modules')` → `POST /settings/modules` with `{ reservations, quotations }`). testids `toggle-module-reservations`, `toggle-module-quotations`.

- [ ] **Step 3: Payload** — extend `buildPayload` for `group === 'modules'` to return `{ reservations: s.modules.reservations, quotations: s.modules.quotations }` (plain object, not FormData).

- [ ] **Step 4: Typecheck + build** → clean.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Admin/SettingsPage.vue resources/js/types/domain/Settings.ts
git commit -m "feat(settings): Modulos tab to override giro module defaults"
```

---

### Task 10: e2e + verification

**Files:**
- Create: `tests/e2e/admin/settings/modules.spec.ts`

**Interfaces:** consumes everything above (seeded `rosa-eterna` tenant is a florería → has Reservas + Cotizaciones).

- [ ] **Step 1: e2e spec**

```ts
import { test, expect, type Page } from '@playwright/test'

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('#password', OWNER.password)
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test('toggling a module in Settings hides and restores its nav item', async ({ page }) => {
    await login(page)
    await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })

    // Florería seed: Cotizaciones present in nav.
    await expect(page.locator('a.sidebar-item[href="/admin/quotations"]')).toHaveCount(1)

    // Open the Modulos tab, turn Cotizaciones off, save.
    await page.getByRole('button', { name: /m[oó]dulos/i }).click()
    await page.locator('[data-testid="toggle-module-quotations"]').click()
    await page.locator('[data-testid="btn-save"]').click()
    await page.waitForTimeout(1200)

    // Reload -> /me now reports quotations disabled -> nav item gone.
    await page.goto(`${TENANT_BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('a.sidebar-item[href="/admin/quotations"]')).toHaveCount(0)

    // Restore so the demo tenant is left as it was.
    await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })
    await page.getByRole('button', { name: /m[oó]dulos/i }).click()
    await page.locator('[data-testid="toggle-module-quotations"]').click()
    await page.locator('[data-testid="btn-save"]').click()
    await page.waitForTimeout(1200)
})
```

- [ ] **Step 2: Run** — `npm run build && artisan optimize:clear` then `npx playwright test tests/e2e/admin/settings/modules.spec.ts --project=chromium-desktop`. Expected: 1 passed. (Adjust the Módulos-tab button name to match SettingsPage's tab label if needed.)

- [ ] **Step 3: Visual QA** — capture a florería (Reservas+Cotizaciones present) vs a ropa/minimarket tenant (both absent) to confirm the nav difference; verify onboarding picker renders the 5 giros. Revert any demo mutation. Delete temp screenshot specs.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/admin/settings/modules.spec.ts
git commit -m "test(e2e): module toggle hides and restores its nav item"
```

---

## Definition of Done

- `business_type` persists per tenant (default `otro`), chosen at signup, isolated per tenant.
- The vertical catalog is the single source of truth; adding a giro is one config entry + enum case.
- `ModuleVisibilityService` composes giro defaults with overrides; core modules never gated.
- A disabled module 403s on the API AND is absent from the nav AND its route redirects.
- Settings → Módulos overrides the giro defaults; nav reflects it after `/me`.
- Vertical-gating hides (no UpgradeLock); plan-gating untouched.
- PHPUnit (`BusinessTypeTest`, `ModuleVisibilityTest`, `ModuleGateTest`, `ModuleSettingsTest`) green; e2e green; vue-tsc + build + Pint clean.
- PR against `develop`, CI green, squash-merged.
