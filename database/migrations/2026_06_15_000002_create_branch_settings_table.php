<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch-aware key-value settings store for tenant EXECUTION config
 * (contact, tax, orders, notifications). POLICY config (brand, locale,
 * quotation/reservation defaults) stays on the `tenants` row.
 *
 * Branch-ready from day 1 (see sprint-8-plan.md):
 *   - `branch_id` is nullable. A row with branch_id = NULL is the TENANT DEFAULT.
 *     A row with branch_id set is a per-branch OVERRIDE.
 *   - The composite UNIQUE includes branch_id so a tenant can hold one default row
 *     PLUS one override per branch for the same (group, key). Without branch_id in
 *     the UNIQUE, per-branch overrides would be impossible.
 *
 * Sprint 8 UX is tenant-wide: the UI only ever reads/writes the default row
 * (branch_id = NULL). The override rows + revert UX activate later when a
 * "current branch" switcher exists — no schema change required then.
 *
 * MySQL gotcha: MySQL treats NULL as DISTINCT in UNIQUE indexes, so the index
 * alone does NOT prevent two (tenant, NULL, group, key) rows. "One default per
 * (tenant, group, key)" is enforced in application code via updateOrCreate
 * (BranchSetting::writeDefault), which SELECTs by `branch_id IS NULL` before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // null = tenant default; set = per-branch override (branches.id is a ULID).
            $table->char('branch_id', 26)->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->string('group', 50);   // 'contact' | 'tax' | 'orders' | 'notifications'
            $table->string('key', 100);
            $table->json('value')->nullable();

            $table->timestamps();

            // branch_id IS part of the UNIQUE so default + per-branch overrides coexist.
            $table->unique(['tenant_id', 'branch_id', 'group', 'key']);
            $table->index(['tenant_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
    }
};
