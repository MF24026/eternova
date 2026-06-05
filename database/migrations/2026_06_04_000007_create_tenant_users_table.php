<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot table linking users to tenants with a per-tenant role.
 *
 * A user can belong to multiple tenants (they switch between them in the SPA),
 * but has exactly one role per tenant. The unique index on (tenant_id, user_id)
 * enforces this at the DB layer.
 *
 * Indexes:
 *  - UNIQUE (tenant_id, user_id) — one role per user per tenant
 *  - (user_id) — enumerate tenants a user belongs to (tenant switcher)
 *  - (tenant_id, role) — list staff/admins of a tenant without full scan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', static function (Blueprint $table): void {
            $table->id();

            $table->char('tenant_id', 26);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('role', ['owner', 'admin', 'staff', 'customer']);
            $table->timestamp('joined_at')->useCurrent();

            $table->timestamps();

            // Referencing tenants.id which is a ULID (26-char string)
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Core constraint: one role per user per tenant
            $table->unique(['tenant_id', 'user_id']);

            // Tenant switcher: "which tenants does user X belong to?"
            $table->index('user_id');

            // Admin list: "who are the admins/staff of tenant X?"
            $table->index(['tenant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
