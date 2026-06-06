<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the /api/v1/notifications/* endpoints.
 *
 * Inserts DatabaseNotification rows directly (no queue, no listener) to keep
 * these tests focused purely on the REST layer.
 */
final class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Use a dedicated database to avoid contention with parallel agents (#33, #36)
     * that also run migrate:fresh on the shared eternova_testing DB.
     *
     * Shares testing_inv_s1e7 with LowStockAlertTest so migrate:fresh runs only once
     * when both test classes run together (RefreshDatabaseState::$migrated stays true).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $dbName = 'testing_inv_s1e7';

        try {
            $this->app['db']->connection('mysql')
                ->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable) {
            $dbName = 'eternova_testing';
        }

        $this->app['config']->set('database.connections.mysql.database', $dbName);
        $this->app['db']->purge('mysql');

        // Do NOT reset RefreshDatabaseState::$migrated here. If LowStockAlertTest ran
        // first in the same process, $migrated is already true and the schema is ready.
        // RefreshDatabase will skip migrate:fresh and just wrap each test in a transaction.
        // If this class runs standalone, $migrated is false and migrate:fresh runs normally.
    }

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($this->tenant, role: 'owner')->create();

        app()->instance('currentTenant', $this->tenant);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a notification row for the current user + tenant.
     */
    private function createNotification(bool $read = false): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'inventory.low_stock',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => json_encode(['type' => 'inventory.low_stock', 'sku' => 'TEST-001']),
            'read_at' => $read ? now() : null,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    // ── unread count ──────────────────────────────────────────────────────────

    public function test_unread_count_returns_correct_number_for_current_tenant_user(): void
    {
        $this->createNotification(read: false);
        $this->createNotification(read: false);
        $this->createNotification(read: true); // already read, should not be counted

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count');

        $response->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_unread_count_does_not_include_other_tenants_notifications(): void
    {
        $this->createNotification(read: false);

        // Notification from a different tenant for the same user
        $tenantB = Tenant::factory()->create();
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'inventory.low_stock',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => json_encode(['type' => 'inventory.low_stock']),
            'read_at' => null,
            'tenant_id' => $tenantB->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count');

        // Only the current tenant's notification is counted.
        $response->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // ── paginated list ────────────────────────────────────────────────────────

    public function test_notifications_index_returns_paginated_list(): void
    {
        $this->createNotification(read: false);
        $this->createNotification(read: true);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_notifications_index_with_unread_only_filter(): void
    {
        $this->createNotification(read: false);
        $this->createNotification(read: true);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications?unread_only=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── mark as read ──────────────────────────────────────────────────────────

    public function test_mark_as_read_updates_read_at_timestamp(): void
    {
        $notification = $this->createNotification(read: false);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_cannot_mark_notification_of_another_user_returns_404(): void
    {
        // Different user in same tenant
        $otherUser = User::factory()->forTenant($this->tenant, role: 'staff')->create();

        // Create a notification for $otherUser
        $notification = DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'inventory.low_stock',
            'notifiable_type' => User::class,
            'notifiable_id' => $otherUser->id,
            'data' => json_encode(['type' => 'inventory.low_stock']),
            'read_at' => null,
            'tenant_id' => $this->tenant->id,
        ]);

        // $this->user tries to mark the other user's notification as read.
        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertNotFound();
    }

    // ── mark all as read ──────────────────────────────────────────────────────

    public function test_mark_all_as_read_marks_only_current_tenant_and_user_notifications(): void
    {
        $this->createNotification(read: false);
        $this->createNotification(read: false);

        // Another tenant's notification for the same user — must NOT be touched.
        $tenantB = Tenant::factory()->create();
        $otherTenantNotification = DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'inventory.low_stock',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => json_encode(['type' => 'inventory.low_stock']),
            'read_at' => null,
            'tenant_id' => $tenantB->id,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson('/api/v1/notifications/read-all');

        $response->assertOk()
            ->assertJsonPath('data.marked_read', 2);

        // The other tenant's notification is still unread.
        $this->assertNull($otherTenantNotification->fresh()->read_at);
    }
}
