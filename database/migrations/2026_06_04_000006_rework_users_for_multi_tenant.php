<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rework users table for global (non-tenant-scoped) users.
 *
 * Users are global entities — their tenant membership and role live in the
 * tenant_users pivot, NOT on the users table itself. This migration:
 *
 *  1. Drops legacy single-tenant columns: tenant_id, role, phone, avatar, provider, provider_id
 *  2. Adds new columns: is_super_admin, avatar_url
 *
 * Sequence matters: foreign-key drops must happen before column drops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            // Drop FK before column (MySQL requires this order)
            if (Schema::hasColumn('users', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            }

            // Legacy single-tenant role column — role now lives in tenant_users
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }

            // Legacy avatar column name (replaced by avatar_url for consistency)
            if (Schema::hasColumn('users', 'avatar')) {
                $table->dropColumn('avatar');
            }

            // Social auth fields — not in scope for this sprint, deferred
            if (Schema::hasColumn('users', 'provider')) {
                $table->dropColumn('provider');
            }
            if (Schema::hasColumn('users', 'provider_id')) {
                $table->dropColumn('provider_id');
            }

            // phone — not part of the auth schema
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }

            // New columns
            $table->boolean('is_super_admin')->default(false)->after('email_verified_at');
            $table->string('avatar_url')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn(['is_super_admin', 'avatar_url']);

            // Restore removed columns so rollback can proceed
            $table->foreignUlid('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->index('tenant_id');
            $table->enum('role', ['admin', 'staff', 'customer'])->default('customer');
            $table->string('phone', 20)->nullable();
            $table->string('avatar')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
        });
    }
};
