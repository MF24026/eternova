<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add staff assignment tracking to the orders table.
 *
 * Design decisions:
 *  - assigned_to is nullable: an unassigned order is the default state.
 *  - nullOnDelete(): if a user is removed, the assignment is silently cleared rather
 *    than blocking the deletion or cascading a hard delete on the order.
 *  - The compound index (tenant_id, assigned_to) supports "orders assigned to me"
 *    queries efficiently — tenant_id first per the project-wide index convention.
 *  - ->after('user_id') places the column logically next to user_id (same concept:
 *    a user-FK on the order), making the schema easier to read in tooling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('assigned_to')
                ->nullable()
                ->after('user_id');

            $table->foreign('assigned_to')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['tenant_id', 'assigned_to']);
            $table->dropColumn('assigned_to');
        });
    }
};
