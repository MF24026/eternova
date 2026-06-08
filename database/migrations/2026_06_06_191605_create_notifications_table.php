<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications table — extended with tenant_id for multi-tenant isolation.
 *
 * Laravel's default notifications table has no tenant awareness. We add:
 *  - tenant_id: so queries can be scoped to the current tenant without
 *    relying solely on the notifiable polymorphic key.
 *  - Index on (tenant_id, created_at): supports the unread-count and
 *    paginated list queries that the NotificationController runs.
 *  - Index on (notifiable_type, notifiable_id, read_at): standard index
 *    that Laravel recommends for User::notifications() relation queries.
 *
 * Note: tenant_id is string(26) matching the ULID primary key on tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();

            // Multi-tenant extension — not in Laravel default schema.
            $table->char('tenant_id', 26)->nullable()->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();

            $table->timestamps();

            // Supports User::notifications() and unread-count queries.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);

            // Supports per-tenant notification list and count queries.
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
