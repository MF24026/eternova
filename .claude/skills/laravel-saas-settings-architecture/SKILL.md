---
name: laravel-saas-settings-architecture
description: Use when designing settings in a multi-tenant SaaS that has locations/branches, when wondering "should this setting be per-tenant or per-branch?", when implementing a "Save as default vs Save only for this location" toggle, when adding a "revert to default" button, when noticing your settings tabs have started showing wrong data after a branch switch. Captures the per-tenant default + per-branch override pattern (lenient resolver, UNIQUE composite, revert UX) that is hard to discover and easy to get wrong.
---

# Laravel SaaS Settings Architecture

You are designing settings for a multi-tenant SaaS where some settings belong to the whole tenant ("our business currency is COP") and some vary per branch ("the kitchen printer at our Downtown branch has IP 10.0.0.99, the Mall branch has its own"). This skill captures the **per-tenant-default with per-branch-override** pattern.

**Origin:** A SaaS where settings started 100% per-tenant, then multi-location was added, and we discovered 4 of 5 settings models silently ignored the branch context (Phase 9 was 50% asleep). The retrofit (#425 audit + Sprint A + #440-iter) introduced badges, banners, revert UX, and a dual-layer resolver. **Doing this from day 1 avoids the mid-flight rewrite.**

## When to use this skill

Activate this skill when:
- Adding a new settings tab
- Asking "should this column be on the `tenants` row or duplicated per branch?"
- Designing a "Save as default" / "Save for this branch only" UX
- A user reports "I changed the setting on branch A and it changed on branch B too" (the bug this pattern prevents)
- Auditing existing settings to classify them as tenant-wide vs per-branch
- Writing the UI to show which sections have per-branch customization

## The 3-axis classification

For every settings field, classify on 3 axes BEFORE writing the migration:

```dot
digraph settings_axis {
  rankdir=TB;
  "Settings field" [shape=box];
  "Is it POLICY (business rule) or EXECUTION (operational config)?" [shape=diamond];
  "Does it vary per location?" [shape=diamond];
  "Who legitimately changes it?" [shape=diamond];

  "Settings field" -> "Is it POLICY (business rule) or EXECUTION (operational config)?";
  "Is it POLICY (business rule) or EXECUTION (operational config)?" -> "Does it vary per location?";
  "Does it vary per location?" -> "Who legitimately changes it?";
}
```

| Field example | Policy / Execution | Per-location? | Who changes |
|---|---|---|---|
| Tax rate | Policy | Sometimes (different states) | Owner |
| Currency | Policy | NO | Owner |
| Business name | Policy | NO | Owner |
| Kitchen printer IP | Execution | YES (each branch has its own) | Owner or Manager |
| KDS template (grid vs kanban) | Execution | YES (each kitchen prefers different) | Owner or Manager |
| Delivery fee | Policy + Execution mix | YES (delivery cost differs by city) | Owner |
| Opening hours | Execution | YES (each branch opens different times) | Owner or Manager |
| Discount rules | Policy | Sometimes | Owner |
| Loyalty program | Policy | NO (one program per brand) | Owner |
| Receipt logo / branding | Policy | NO | Owner |

**Heuristic**:
- **Policy + not-per-location** → single row, `tenant_settings` table or columns on `tenants`. Owner-only.
- **Execution + per-location** → row per `(tenant_id, branch_id)` with **lenient fallback** to tenant default. Owner or Manager (Manager scoped to their branch).
- **Mixed** → split into two fields: one on tenant (default), one per-branch override.

## The data model — per-branch override with lenient fallback

For settings that vary per-branch, **do not** put the column on the `branches` table. Use a dedicated settings table that supports BOTH the tenant default (one row with `branch_id = null`) AND per-branch overrides (rows with `branch_id` set).

```php
Schema::create('printer_settings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('type');                  // 'kitchen' | 'customer'
    $table->boolean('is_enabled')->default(false);
    $table->string('connection_type');       // 'network' | 'usb' | 'bluetooth'
    $table->ipAddress('ip_address')->nullable();
    $table->unsignedSmallInteger('port')->nullable();
    $table->enum('width_mm', ['58', '80'])->default('80');
    $table->boolean('auto_print')->default(true);
    $table->timestamps();

    // CRITICAL: composite UNIQUE including branch_id
    // Without branch_id in the UNIQUE, you cannot have a default row + branch overrides for same type
    $table->unique(['tenant_id', 'branch_id', 'type']);
});
```

**Why `branch_id` is part of the UNIQUE**: so you can have:
- 1 row for `(tenant_id=5, branch_id=null, type='kitchen')` → the default
- 1 row for `(tenant_id=5, branch_id=42, type='kitchen')` → Downtown override
- 1 row for `(tenant_id=5, branch_id=43, type='kitchen')` → Mall override

Without `branch_id` in UNIQUE, you can only have ONE kitchen printer config per tenant — branch overrides become impossible.

**MySQL gotcha**: MySQL treats `NULL` as distinct in UNIQUE indexes, so `(5, NULL, 'kitchen')` and `(5, NULL, 'kitchen')` would BOTH be allowed (collision). Enforce "one default per tenant per type" in application code via a model `booted()` hook or upsert pattern.

## The lenient resolver — model `BranchAwareSetting` concern

Every model that supports per-branch overrides uses this concern:

```php
namespace App\Models\Concerns;

trait BranchAwareSetting
{
    /**
     * Resolve the active row for the current tenant + branch context.
     * Lenient: tries branch override first, falls back to tenant default.
     *
     * Returns a NEW (unsaved) instance with defaults if neither exists,
     * so the UI never crashes on a fresh tenant.
     */
    public static function active(): static
    {
        if (! app()->bound('current_tenant')) {
            return static::defaults();
        }

        $tenantId = app('current_tenant')->id;
        $branchId = app()->bound('current_branch') ? app('current_branch')->id : null;

        // Try branch override first
        if ($branchId !== null) {
            $override = static::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->first();
            if ($override) return $override;
        }

        // Fall back to tenant default (branch_id = null)
        $default = static::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('branch_id')
            ->first();
        if ($default) return $default;

        return static::defaults();
    }

    /**
     * Override per model — returns a NEW instance with sensible defaults.
     */
    abstract public static function defaults(): static;
}
```

**Same shape for "per type" models** (like `PrinterSettings` with `type='kitchen'` and `type='customer'`):

```php
public static function forType(string $type, ?int $branchId = null): static
{
    $tenantId = app('current_tenant')->id;
    $branchId ??= app()->bound('current_branch') ? app('current_branch')->id : null;

    if ($branchId !== null) {
        $override = static::query()->where(['tenant_id'=>$tenantId, 'branch_id'=>$branchId, 'type'=>$type])->first();
        if ($override) return $override;
    }
    return static::query()->where(['tenant_id'=>$tenantId, 'type'=>$type])->whereNull('branch_id')->first()
        ?? static::defaults($type);
}
```

## The UI pattern — 3 components

When a Settings tab is per-branch-aware, render 3 components together:

### 1. `BranchScopeSelector` (write-side toggle)

A pill toggle in the form header that decides where the form submission writes:
- "Save as default for all branches" → POST with `branch_scope=all_branches`, backend writes `branch_id = null`
- "Save only for {current_branch_name}" → POST with `branch_scope=this_branch`, backend writes `branch_id = current_branch->id`

**Defaults intelligently**:
- If a branch override already exists for the current branch → default to `this_branch`
- If the user is operating a non-default branch → default to `this_branch` (anti-trap: avoid accidentally editing the tenant default while operating a specific branch)
- If the user is on the default branch and no override exists → default to `all_branches`

### 2. `BranchOverrideBadge` (read-side indicator)

A small amber pill next to the section title that appears when:
- `row.branch_id !== null` AND
- `row.branch_id === currentBranchId` AND
- `currentBranchId !== defaultBranchId` (suppress noise on default branch)

Copy: "Solo {branch_name}" / "Only at {branch_name}".

### 3. `RevertToDefaultButton` (Owner-only escape hatch)

A "Revert to default" button next to the section that:
- Is visible only when the section has a branch override AND the user is Owner (`can_see_all_branches = true`)
- On click, opens a `useConfirmDialog` modal: "{branch_name} will stop using its own value for {section} and inherit the value from the business defaults."
- On confirm, calls `DELETE /settings/branch-override/{kind}` — deletes the row, fallback to default takes effect on next read

**Manager sees the badge but NOT the revert button.** Revert is an Owner-only operation (it changes tenant-wide policy, indirectly).

### Banner (optional, top-of-tab)

A consolidated banner above the tab content: "This branch has N custom configurations in this tab." Hidden when count = 0 OR user is on default branch.

## The DELETE endpoint — Owner-only

```php
// routes/web.php — inside settings group
Route::delete('/branch-override/{kind}', [SettingsController::class, 'deleteBranchOverride'])
    ->name('branch-override.destroy')
    ->where('kind', 'kitchen-printer|customer-printer|kitchen|delivery|takeaway|dine-in');
```

```php
// SettingsController
private const BRANCH_OVERRIDE_KIND_MAP = [
    'kitchen-printer'  => [PrinterSettings::class,  'kitchen'],
    'customer-printer' => [PrinterSettings::class,  'customer'],
    'kitchen'          => [KitchenSettings::class,  null],
    'delivery'         => [DeliverySettings::class, null],
    'takeaway'         => [TakeawaySettings::class, null],
    'dine-in'          => [DineInSettings::class,   null],
];

public function deleteBranchOverride(Request $request, string $kind): RedirectResponse
{
    $user = $request->user();
    $tenantId = app()->bound('current_tenant') ? (int) app('current_tenant')->id : null;
    abort_unless($user && $tenantId !== null && $user->canSeeAllBranches($tenantId), 403);

    $branch = app()->bound('current_branch') ? app('current_branch') : null;
    abort_unless($branch && ! $branch->is_default, 422, 'No override to revert on the default branch.');

    [$modelClass, $type] = self::BRANCH_OVERRIDE_KIND_MAP[$kind];

    $query = $modelClass::query()
        ->where('tenant_id', $tenantId)
        ->where('branch_id', $branch->id);
    if ($type !== null) $query->where('type', $type);

    $row = $query->first();
    abort_unless($row, 404, 'No branch-specific config for this branch.');
    $row->delete();

    return back()->with('success', 'This branch now uses the business default.');
}
```

**Drift warning**: the `BRANCH_OVERRIDE_KIND_MAP` constant and the route regex must match. When you add a new BranchAwareSetting model, update BOTH places. Add a comment on the constant pointing to the route.

## Owner-on-default-branch UX trap

When the Owner is operating the **default** branch (e.g. "Main Store"):
- They have full Owner powers
- BUT the `BranchScopeSelector` should not appear (or should be collapsed) — choosing "Only at Main Store" when Main Store IS the default is logically meaningless
- Badges should NOT appear (even though `branch_id === currentBranchId` matches, suppress because `currentBranchId === defaultBranchId`)
- Revert button hidden (nothing to revert; you ARE the default)

This is what saves the Owner from edge-case confusion. The `BranchOverrideBadge` visibility rule enforces it:
```js
const visible = computed(() => {
    if (props.branchId === null) return false;
    if (Number(props.branchId) !== Number(currentBranchId.value)) return false;
    if (Number(currentBranchId.value) === Number(defaultBranchId.value)) return false;
    return true;
});
```

## Tests — non-negotiable

`tests/Feature/Settings/BranchAwareSettingsTest.php` covers the resolver:

1. `it_resolves_branch_override_when_present` — branch row wins over tenant default
2. `it_falls_back_to_tenant_default_when_no_override` — branch_id=null wins when no override
3. `it_returns_safe_defaults_when_neither_exists` — fresh tenant doesn't crash
4. `it_isolates_overrides_per_tenant` — Tenant A override doesn't bleed to Tenant B
5. `it_isolates_overrides_per_branch` — Branch A's override invisible to Branch B
6. `it_allows_owner_to_save_as_default` — POST `branch_scope=all_branches` writes `branch_id=null`
7. `it_allows_owner_to_save_as_branch_override` — POST `branch_scope=this_branch` writes `branch_id=$branch->id`
8. `it_creates_new_row_when_override_doesnt_exist_yet` — first save creates branch row
9. `it_updates_existing_override_row` — subsequent save updates same row, doesn't duplicate
10. `it_clones_settings_from_default_when_creating_new_branch` — new branch gets pre-seeded from tenant default

`tests/Feature/Settings/BranchOverrideRevertTest.php` covers the delete flow:

1. `it_lets_owner_delete_override_and_fall_back_to_default` — happy path
2. `it_rejects_delete_when_no_override_exists` — 404
3. `it_rejects_delete_from_default_branch` — 422 (no override to revert)
4. `it_blocks_manager_from_deleting_override` — 403, row remains
5. `it_isolates_delete_per_tenant` — Tenant A delete doesn't affect Tenant B's override

## Anti-patterns — never do this

- Putting a per-branch column on the `branches` table itself — `branches.kitchen_printer_ip` does NOT scale (5 different printer types = 5 columns; what about more?). Use a separate `printer_settings` table with row-per-(tenant, branch, type).
- Forgetting `branch_id` in the UNIQUE index — silently breaks per-branch overrides for that type.
- Reading settings without going through the lenient resolver — `PrinterSettings::where('tenant_id', $id)->first()` may return the default OR an override depending on insertion order. Use `PrinterSettings::active()` or `::forType(...)`.
- Hardcoding "save the override" without a way to revert — users will get stuck on a bad config.
- Making Revert available to Managers — they can effectively wipe Owner's tenant-wide policy by deleting all overrides.
- Showing the badge "Only at Main Store" when Main Store IS the default — pure UI noise, confuses users.
- Cloning the entire settings table when a new branch is created (eager) — wastes rows. Better: lazy fallback resolves to the default; only create override rows when user actually customizes.
- Mixing the toggle terminology — pick ONE: "Save as default vs Save only for this branch", or "All branches vs This branch only". Don't switch midstream.

## Auditing existing settings (retrofit)

If your SaaS started single-tenant or single-location, run this audit BEFORE wiring multi-branch:

1. List every settings column on every table.
2. For each, classify on the 3-axis matrix above.
3. Identify drift: did you have a "tenant" setting that's actually being used per-branch in production (e.g. people emailed support saying "I can't have different printers per branch")?
4. Decide per setting: tenant-only, per-branch, or both with override.
5. Schedule the migrations: `branch_id` column nullable → backfill → composite UNIQUE → resolver concern → UI badges → revert UX.

The full retrofit took 6 sprints in the original POSLatam audit (#425 + Sprint A + #440-iter). **Doing it from day 1 in a new SaaS is ~1 sprint.**

## Cross-references

- `laravel-saas-multi-tenant-foundation` — defines `tenant_id` + `branch_id` infrastructure this builds on
- `laravel-saas-auth-granularity` — Owner-only revert button, Manager-allowed badge view
- `vue-inertia-frontend-system` — `BranchScopeSelector`, `BranchOverrideBadge`, `BranchOverrideBanner`, `RevertToDefaultButton` component patterns
- `saas-testing-dual-layer` — settings tests use the lenient resolver pattern
