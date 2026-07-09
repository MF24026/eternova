# Admin Theme System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each tenant pick an admin/POS theme — Ethereal (rosa, default) or Minimalista (emerald/slate) — as a per-tenant default, while light/dark stays a per-user preference.

**Architecture:** Add a `theme-minimal` root class on `<html>` (orthogonal to `.dark`) that overrides the existing runtime CSS tokens with a neutral palette; the whole admin already renders through those tokens, so no component markup changes. The choice is a `brand`-group setting stored on the `tenants` table, exposed at bootstrap via `/me`, applied by the `ui` store like dark mode.

**Coherence note (verified 2026-07-09):** the shared component layer in `app.css` (`.btn*`, `.card`, `.field`, `.tier`/`.tier-mid`/`.tier-high`, `.scroll` scrollbars, `.tabs`, `.slideover`) is 100% token-driven (`var(--*)`), and spacing/radii are theme-agnostic constants (e.g. `.card` padding 24px). So overriding the tokens re-themes the entire coherent system automatically — padding, buttons, No-Line tier backgrounds and scrollbars stay coherent; only colours flip. The ONE hardcoded colour found in the component layer is `.slideover-scrim { background: rgba(61,47,50,0.28) }` (the rosa `--on-surface` tone) — Task 4 converts it to a `--scrim` token so it themes too. (Inline `rgba(61,47,50,.42)` scrims inside the full-screen overlay `.vue` components are a separate minor follow-up, not in this phase.)

**Tech Stack:** Laravel 12 (PHP 8.4), Vue 3 SPA (vue-router + Pinia + axios, NOT Inertia), Tailwind 4, MySQL, Pest/PHPUnit, Playwright.

## Global Constraints

- No emojis anywhere — code, comments, commits, identifiers, UI. Lucide icons only.
- No AI co-author / `Co-Authored-By` trailer in commits. Commits in English, Conventional Commits.
- Feature branch `feat/admin-theme-system` → PR against `develop` (never `main`); squash-merge.
- Multi-tenant: `admin_theme` is per-tenant; never leak across tenants; brand is a POLICY group (owner/admin only, staff cannot alter).
- Two hand-authored palettes only; no arbitrary custom admin colours (that stays storefront-only).
- Ethereal Boutique discipline for BOTH palettes: tokens only, No-Line rule, never pure black text, dark mode first-class.
- `admin_theme` allowed values: `ethereal` | `minimal`. Default `ethereal`.
- Dual-layer testing (PHPUnit + Playwright) before PR; visual QA in all four theme×mode combinations.
- Testing DB gotcha: `LowStock`/inventory-style dedicated DBs aside, feature tests use `RefreshDatabase`; if migrate errors with "table already exists", drop+recreate `eternova_testing`. e2e runs against the built bundle — run `npm run build` + `artisan optimize:clear` before Playwright.

---

### Task 1: Backend — `admin_theme` column, enum, model

**Files:**
- Create: `database/migrations/2026_07_09_000001_add_admin_theme_to_tenants.php`
- Create: `app/Modules/Settings/Enums/AdminTheme.php`
- Modify: `app/Modules/Tenancy/Models/Tenant.php` (add to `$fillable`)
- Test: `tests/Feature/Settings/AdminThemeTest.php`

**Interfaces:**
- Produces: `AdminTheme` string enum with cases `Ethereal = 'ethereal'`, `Minimal = 'minimal'`; `tenants.admin_theme` string column default `'ethereal'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tenant_defaults_to_ethereal_theme(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('ethereal', $tenant->admin_theme);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=AdminThemeTest`
Expected: FAIL — `admin_theme` column/attribute does not exist.

- [ ] **Step 3: Write the migration**

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
            $table->string('admin_theme', 20)->default('ethereal')->after('secondary_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('admin_theme');
        });
    }
};
```

- [ ] **Step 4: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

enum AdminTheme: string
{
    case Ethereal = 'ethereal';
    case Minimal = 'minimal';
}
```

- [ ] **Step 5: Add `admin_theme` to the Tenant model `$fillable`**

In `app/Modules/Tenancy/Models/Tenant.php`, add `'admin_theme',` to the `$fillable` array immediately after `'secondary_color',`.

- [ ] **Step 6: Run the migration and the test**

Run: `./vendor/bin/sail artisan migrate --force && ./vendor/bin/sail artisan test --filter=AdminThemeTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_09_000001_add_admin_theme_to_tenants.php app/Modules/Settings/Enums/AdminTheme.php app/Modules/Tenancy/Models/Tenant.php tests/Feature/Settings/AdminThemeTest.php
git commit -m "feat(settings): add admin_theme column, enum and default"
```

---

### Task 2: Backend — Settings read/write + validation

**Files:**
- Modify: `app/Modules/Settings/Services/SettingsService.php` (brand mapping + resolve)
- Modify: `app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php` (rule + message)
- Test: `tests/Feature/Settings/AdminThemeTest.php` (extend)

**Interfaces:**
- Consumes: `AdminTheme` enum (Task 1); `tenants.admin_theme` column (Task 1).
- Produces: `GET /api/v1/settings` returns `brand.admin_theme`; `PATCH /api/v1/settings` with `brand.admin_theme` persists it; invalid value → 422.

- [ ] **Step 1: Write the failing tests (append to AdminThemeTest)**

```php
    // API SHAPE (verified against routes/api/v1/settings.php):
    //   GET  /api/v1/settings          -> all groups, show returns data.{group}.{key}
    //   POST /api/v1/settings/{group}  -> update ONE group with a FLAT payload
    //   (the {group} rules live in UpdateSettingsRequest::rules() match arm).
    // There is NO PATCH route and NO nested "brand" wrapper. Use POST + flat body.

    public function test_settings_show_returns_admin_theme(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/settings'))
            ->assertOk()
            ->assertJsonPath('data.brand.admin_theme', 'ethereal');
    }

    public function test_owner_can_switch_admin_theme_to_minimal(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/settings/brand'), [
                'business_name' => $tenant->business_name,
                'admin_theme' => 'minimal',
            ])
            ->assertOk();

        $this->assertSame('minimal', $tenant->fresh()->admin_theme);
    }

    public function test_invalid_admin_theme_is_rejected(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/settings/brand'), [
                'business_name' => $tenant->business_name,
                'admin_theme' => 'neon',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('admin_theme');
    }

    public function test_theme_change_is_isolated_per_tenant(): void
    {
        [$ownerA, $tenantA] = $this->ownerForTenant();
        [, $tenantB] = $this->ownerForTenant();

        $this->actingAs($ownerA)
            ->postJson($this->tenantUrl($tenantA, 'api/v1/settings/brand'), [
                'business_name' => $tenantA->business_name,
                'admin_theme' => 'minimal',
            ])
            ->assertOk();

        $this->assertSame('minimal', $tenantA->fresh()->admin_theme);
        $this->assertSame('ethereal', $tenantB->fresh()->admin_theme);
    }
```

Add the imports and helper at the top of the class (below `use RefreshDatabase;`):

```php
    use \Tests\Support\ActingAsTenantMember;

    /**
     * @return array{0: \App\Models\User, 1: Tenant}
     */
    private function ownerForTenant(): array
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $owner = \App\Models\User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        return [$owner, $tenant];
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail artisan test --filter=AdminThemeTest`
Expected: FAIL — `admin_theme` absent from settings payload / not validated.

- [ ] **Step 3: Add to SettingsService brand group + resolve**

In `app/Modules/Settings/Services/SettingsService.php`:
- In `TENANT_ROW_GROUPS`, change the `'brand'` entry to include `'admin_theme'`:
  ```php
  'brand' => ['business_name', 'primary_color', 'secondary_color', 'admin_theme'],
  ```
- In `resolveAll()`'s `'brand'` array, add after `'secondary_color'`:
  ```php
  'admin_theme' => $tenant->admin_theme,
  ```

- [ ] **Step 4: Add validation rule + message to UpdateSettingsRequest**

In `app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php`:
- Add `use App\Modules\Settings\Enums\AdminTheme;` and `use Illuminate\Validation\Rule;` (if not present) at the top.
- In the `'brand'` rules array, after `'secondary_color'`, add:
  ```php
  'admin_theme' => ['sometimes', Rule::enum(AdminTheme::class)],
  ```
- In the messages array add:
  ```php
  'admin_theme.enum' => 'El tema debe ser Ethereal o Minimalista.',
  ```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter=AdminThemeTest`
Expected: PASS (all cases).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Settings/Services/SettingsService.php app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php tests/Feature/Settings/AdminThemeTest.php
git commit -m "feat(settings): read, write and validate brand.admin_theme"
```

---

### Task 3: Backend — expose `admin_theme` at bootstrap (`/me`)

**Files:**
- Modify: `app/Modules/Auth/Http/Resources/UserResource.php`
- Test: `tests/Feature/Settings/AdminThemeTest.php` (extend)

**Interfaces:**
- Consumes: `tenants.admin_theme` (Task 1).
- Produces: `GET /api/v1/me` → each `tenants[]` entry carries `admin_theme`.

- [ ] **Step 1: Write the failing test (append)**

```php
    public function test_me_exposes_admin_theme_on_tenant_membership(): void
    {
        [$owner, $tenant] = $this->ownerForTenant();
        $tenant->update(['admin_theme' => 'minimal']);

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, 'api/v1/me'))
            ->assertOk()
            ->assertJsonPath('data.tenants.0.admin_theme', 'minimal');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/sail artisan test --filter=AdminThemeTest`
Expected: FAIL — `admin_theme` missing from the tenant block.

- [ ] **Step 3: Add `admin_theme` to the UserResource tenant block**

In `app/Modules/Auth/Http/Resources/UserResource.php`, inside the `tenants` map closure, add after `'business_name' => $tenant->business_name,`:

```php
'admin_theme' => $tenant->admin_theme,
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/sail artisan test --filter=AdminThemeTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Auth/Http/Resources/UserResource.php tests/Feature/Settings/AdminThemeTest.php
git commit -m "feat(auth): expose tenant admin_theme in /me for SPA bootstrap"
```

---

### Task 4: Frontend — Minimalista palette tokens in `app.css`

**Files:**
- Modify: `resources/css/app.css` (add `.theme-minimal` and `.theme-minimal.dark` after the `.dark {}` block, before the `* { box-sizing }` reset)

**Interfaces:**
- Produces: root class `theme-minimal` (and `theme-minimal` + `dark` combined) that overrides every runtime token with the neutral palette.

- [ ] **Step 1: Add the Minimalista token blocks**

Insert immediately after the closing `}` of `.dark {` (currently line ~165, before `* { box-sizing: border-box; }`):

```css
/* =============================================
   Minimalista theme — neutral emerald/slate.
   Overrides the SAME runtime tokens the rosa :root/.dark define; the @theme
   mapping and every component pick these up unchanged. Both blocks list the
   COMPLETE token set — any token omitted falls back to the rosa value and
   leaks pink into the neutral theme. Must come AFTER :root and .dark so the
   equal-specificity light rules win by source order.
   ============================================= */
.theme-minimal {
    --surface: #f8fafc;
    --surface-lowest: #ffffff;
    --surface-low: #f1f5f9;
    --surface-mid: #e9eef4;
    --surface-high: #e2e8f0;
    --surface-highest: #dbe2ea;

    --primary: #059669;
    --primary-dim: #047857;
    --primary-container: #d1fae5;
    --on-primary: #ffffff;

    --secondary: #475569;
    --secondary-container: #e2e8f0;
    --on-secondary-container: #334155;

    --on-surface: #1e293b;
    --on-surface-variant: #475569;
    --outline-variant: rgba(100, 116, 139, 0.14);
    --outline-soft: rgba(100, 116, 139, 0.30);

    --success: #059669;
    --success-container: #d1fae5;
    --warning: #b45309;
    --warning-container: #fef3c7;
    --error: #dc2626;
    --error-container: #fee2e2;
    --info: #0284c7;
    --info-container: #e0f2fe;

    --gradient: linear-gradient(135deg, #059669, #34d399);
    --gradient-soft: linear-gradient(135deg, #34d399, #a7f3d0);
    --gradient-bloom: linear-gradient(160deg, #f1f5f9 0%, #e2e8f0 40%, #d1fae5 100%);

    --shadow-ambient: 0 12px 32px rgba(15, 23, 42, 0.06);
    --shadow-rest: 0 4px 16px rgba(15, 23, 42, 0.04);
    --shadow-lifted: 0 18px 48px rgba(15, 23, 42, 0.10);

    --scrim: rgba(15, 23, 42, 0.28);
}

.theme-minimal.dark {
    --surface: #0f172a;
    --surface-lowest: #0b1220;
    --surface-low: #1e293b;
    --surface-mid: #263449;
    --surface-high: #334155;
    --surface-highest: #3f4d63;

    --primary: #34d399;
    --primary-dim: #10b981;
    --primary-container: #065f46;
    --on-primary: #052e22;

    --secondary: #94a3b8;
    --secondary-container: #334155;
    --on-secondary-container: #e2e8f0;

    --on-surface: #e2e8f0;
    --on-surface-variant: #94a3b8;
    --outline-variant: rgba(148, 163, 184, 0.12);
    --outline-soft: rgba(148, 163, 184, 0.22);

    --success: #34d399;
    --success-container: #064e3b;
    --warning: #fbbf24;
    --warning-container: #3a2f12;
    --error: #f87171;
    --error-container: #3a1f1f;
    --info: #38bdf8;
    --info-container: #0c2a3a;

    --gradient: linear-gradient(135deg, #34d399, #059669);
    --gradient-soft: linear-gradient(135deg, #334155, #065f46);
    --gradient-bloom: linear-gradient(160deg, #1e293b 0%, #263449 50%, #065f46 100%);

    --shadow-ambient: 0 12px 32px rgba(0, 0, 0, 0.32);
    --shadow-rest: 0 4px 16px rgba(0, 0, 0, 0.24);
    --shadow-lifted: 0 18px 48px rgba(0, 0, 0, 0.4);

    --scrim: rgba(0, 0, 0, 0.5);
}
```

- [ ] **Step 2: Tokenize the shared slideover scrim (kill the one hardcoded colour)**

The coherent component layer is token-driven except `.slideover-scrim`. Make it a token so it themes:

1. In `:root` (the rosa block, after `--shadow-lifted`), add: `--scrim: rgba(61, 47, 50, 0.28);`
2. In `.dark` (after its `--shadow-lifted`), add: `--scrim: rgba(0, 0, 0, 0.5);`
3. Change `.slideover-scrim`'s background from `rgba(61, 47, 50, 0.28)` to `var(--scrim)`.

(The `--scrim` values for `.theme-minimal` / `.theme-minimal.dark` are already in the blocks above.)

- [ ] **Step 3: Build to verify the CSS compiles**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds with no CSS errors.

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css
git commit -m "feat(ui): add Minimalista theme palette + tokenize slideover scrim"
```

---

### Task 5: Frontend — `ui` store theme state + `useTheme` + bootstrap apply

**Files:**
- Modify: `resources/js/stores/ui.ts` (theme state, initTheme, setTheme, applyTheme)
- Modify: `resources/js/composables/useTheme.ts` (expose theme + setTheme)
- Modify: `resources/js/types/domain/User.ts` (add `admin_theme` to tenant membership type)
- Modify: `resources/js/components/layout/AdminLayout.vue` (reconcile to tenant theme on mount)

**Interfaces:**
- Consumes: `/me` tenant block `admin_theme` (Task 3).
- Produces: `useUiStore().theme` (`'ethereal' | 'minimal'`), `setTheme(t)`, `initTheme()`; `useTheme()` returns `{ theme, setTheme }` alongside existing `isDark`/`toggle`.

- [ ] **Step 1: Add theme state to the ui store**

In `resources/js/stores/ui.ts`, after the `darkMode` block, add:

```ts
    const theme = ref<'ethereal' | 'minimal'>('ethereal')

    function initTheme(): void {
        const stored = localStorage.getItem('eternova-admin-theme')
        theme.value = stored === 'minimal' ? 'minimal' : 'ethereal'
        applyTheme()
    }

    function setTheme(next: 'ethereal' | 'minimal'): void {
        theme.value = next
        localStorage.setItem('eternova-admin-theme', next)
        applyTheme()
    }

    function applyTheme(): void {
        if (theme.value === 'minimal') {
            document.documentElement.classList.add('theme-minimal')
        } else {
            document.documentElement.classList.remove('theme-minimal')
        }
    }
```

Then add `theme`, `initTheme`, `setTheme` to the store's returned object.

- [ ] **Step 2: Expose theme in `useTheme`**

In `resources/js/composables/useTheme.ts`, return `theme` (read from `ui.theme`) and a `setTheme` that calls `ui.setTheme(...)`, alongside the existing `isDark`/`toggle`. Keep the existing signature additive (do not remove `isDark`/`toggle`).

- [ ] **Step 3: Add `admin_theme` to the User tenant membership type**

In `resources/js/types/domain/User.ts`, on the interface describing a tenant membership (the one with `id`, `slug`, `business_name`, `role`, `is_current`), add:

```ts
    admin_theme: 'ethereal' | 'minimal'
```

- [ ] **Step 4: Init + reconcile on admin mount**

In `resources/js/components/layout/AdminLayout.vue`, in the existing `onMounted` (or add one), after the user/tenant is available from the auth store:

```ts
import { useUiStore } from '@/stores/ui'
import { useAuthStore } from '@/stores/auth'
// ...
const ui = useUiStore()
const auth = useAuthStore()

onMounted(() => {
    ui.initTheme() // instant paint from localStorage
    const current = auth.currentUser?.tenants.find((t) => t.is_current)
    if (current) ui.setTheme(current.admin_theme) // backend is source of truth
})
```

(If `AdminLayout.vue` already has an `onMounted`, merge these lines into it rather than adding a second hook.)

- [ ] **Step 5: Typecheck + build**

Run: `./vendor/bin/sail npx vue-tsc --noEmit && ./vendor/bin/sail npm run build`
Expected: no type errors; build succeeds.

- [ ] **Step 6: Commit**

```bash
git add resources/js/stores/ui.ts resources/js/composables/useTheme.ts resources/js/types/domain/User.ts resources/js/components/layout/AdminLayout.vue
git commit -m "feat(ui): apply per-tenant admin theme from bootstrap with localStorage cache"
```

---

### Task 6: Frontend — Settings > Apariencia selector

**Files:**
- Modify: `resources/js/pages/Admin/SettingsPage.vue` (theme selector in the brand section + include `admin_theme` in the save payload)
- Modify: `resources/js/types/domain/Settings.ts` (add `admin_theme` to the brand block type)

**Interfaces:**
- Consumes: `useTheme().setTheme` (Task 5); `PATCH /api/v1/settings` `brand.admin_theme` (Task 2).
- Produces: two theme cards `data-testid="theme-option-ethereal"` / `theme-option-minimal` that set `settings.brand.admin_theme`, apply it live via `setTheme`, and persist on save.

- [ ] **Step 1: Add `admin_theme` to the Settings brand type**

In `resources/js/types/domain/Settings.ts`, on the brand block interface add:

```ts
    admin_theme: 'ethereal' | 'minimal'
```

- [ ] **Step 2: Add the selector to SettingsPage brand section**

In the brand tab of `resources/js/pages/Admin/SettingsPage.vue`, add a "Apariencia del panel" block with two cards. Use `useTheme()` so the choice previews live:

```vue
<div class="mt-6">
    <p class="label-gilt mb-1">Apariencia del panel</p>
    <p class="text-xs text-on-surface-variant mb-3">
        Define el tema del panel de administración para todo tu equipo. La vitrina pública usa tus colores de marca aparte.
    </p>
    <div class="grid grid-cols-2 gap-3 max-w-md">
        <button
            type="button"
            class="p-4 rounded-[var(--r-lg)] text-left transition-shadow"
            :style="settings.brand.admin_theme !== 'minimal'
                ? 'background: var(--surface-low); box-shadow: 0 0 0 2px var(--primary)'
                : 'background: var(--surface-low)'"
            data-testid="theme-option-ethereal"
            @click="selectTheme('ethereal')"
        >
            <span class="block w-full h-8 rounded-[var(--r-md)] mb-2" style="background: linear-gradient(135deg, #7c545d, #f8c4cf)" />
            <span class="text-sm font-semibold text-on-surface">Ethereal</span>
        </button>
        <button
            type="button"
            class="p-4 rounded-[var(--r-lg)] text-left transition-shadow"
            :style="settings.brand.admin_theme === 'minimal'
                ? 'background: var(--surface-low); box-shadow: 0 0 0 2px var(--primary)'
                : 'background: var(--surface-low)'"
            data-testid="theme-option-minimal"
            @click="selectTheme('minimal')"
        >
            <span class="block w-full h-8 rounded-[var(--r-md)] mb-2" style="background: linear-gradient(135deg, #059669, #34d399)" />
            <span class="text-sm font-semibold text-on-surface">Minimalista</span>
        </button>
    </div>
</div>
```

- [ ] **Step 3: Wire `selectTheme` and the save payload**

In `SettingsPage.vue` `<script setup>`:
- Import and use theme: `import { useTheme } from '@/composables/useTheme'` then `const { setTheme } = useTheme()`.
- Add:
  ```ts
  function selectTheme(next: 'ethereal' | 'minimal'): void {
      settings.brand.admin_theme = next
      setTheme(next) // live preview
  }
  ```
- Where the brand payload is assembled for the PATCH (near the existing `primary_color` append at line ~198), include `admin_theme`:
  ```ts
  form.append('admin_theme', s.brand.admin_theme)
  ```
  (Match the existing payload style in that function; if it builds a plain object rather than FormData, add `admin_theme: s.brand.admin_theme` instead.)

- [ ] **Step 4: Typecheck + build**

Run: `./vendor/bin/sail npx vue-tsc --noEmit && ./vendor/bin/sail npm run build`
Expected: no type errors; build succeeds.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Admin/SettingsPage.vue resources/js/types/domain/Settings.ts
git commit -m "feat(settings): Apariencia selector for admin theme with live preview"
```

---

### Task 7: e2e + visual QA

**Files:**
- Create: `tests/e2e/admin/settings/theme.spec.ts`

**Interfaces:**
- Consumes: everything above (settings persistence, `/me` bootstrap, `theme-minimal` class, selector testids).

- [ ] **Step 1: Write the e2e spec**

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

test.describe('Admin theme selector', () => {
    test('switch to Minimalista themes the admin and persists', async ({ page }) => {
        await login(page)
        await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })

        await page.locator('[data-testid="theme-option-minimal"]').click()
        await expect(page.locator('html')).toHaveClass(/theme-minimal/)

        // Persist: save the settings form (reuse the page's save control).
        await page.getByRole('button', { name: /guardar/i }).first().click()
        await page.waitForTimeout(1000)

        // Reload: the tenant default now boots minimal.
        await page.goto(`${TENANT_BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
        await expect(page.locator('html')).toHaveClass(/theme-minimal/)

        // Revert to Ethereal to leave demo data as-is.
        await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })
        await page.locator('[data-testid="theme-option-ethereal"]').click()
        await expect(page.locator('html')).not.toHaveClass(/theme-minimal/)
        await page.getByRole('button', { name: /guardar/i }).first().click()
        await page.waitForTimeout(1000)
    })
})
```

- [ ] **Step 2: Build + clear opcache, then run the e2e**

Run:
```bash
./vendor/bin/sail npm run build
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail npx playwright test tests/e2e/admin/settings/theme.spec.ts --project=chromium-desktop
```
Expected: 1 passed. (If the save button label differs, adjust the `getByRole` name to match SettingsPage's actual save control.)

- [ ] **Step 3: Visual QA (manual, screenshots)**

Capture the admin dashboard + POS in all four combinations and confirm no pink leaks into Minimalista and contrast holds:
- Ethereal light, Ethereal dark, Minimalista light, Minimalista dark.
Use a temporary Playwright screenshot spec (delete it after) or the qa-engineer flow. Verify: in Minimalista, `getComputedStyle` of a `.btn-primary` background is emerald (`rgb(5, 150, 105)` light / `rgb(52, 211, 153)` dark), not rosa.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/admin/settings/theme.spec.ts
git commit -m "test(e2e): admin theme selector switches, persists and reverts"
```

---

## Definition of Done

- `admin_theme` persists per tenant, defaults `ethereal`, validated as enum, isolated per tenant.
- `/me` carries `admin_theme`; the admin boots into the tenant's theme with no flash (localStorage cache).
- Settings > Apariencia switches the whole admin live and on reload; storefront unaffected.
- Minimalista shows zero rosa leakage in light AND dark; contrast holds.
- PHPUnit `AdminThemeTest` green; e2e `theme.spec.ts` green; vue-tsc + build + Pint clean.
- PR opened against `develop`, CI green, squash-merged.
