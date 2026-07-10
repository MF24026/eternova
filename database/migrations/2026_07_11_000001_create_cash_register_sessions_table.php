<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->string('branch_id', 26);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            // The cashier who opened the session.
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Per-tenant sequential number for the arqueo record.
            $table->unsignedInteger('session_number');

            // Money in cents (bigint), never decimal.
            $table->bigInteger('opening_amount_cents');
            $table->bigInteger('closing_amount_cents')->nullable();  // counted at close
            $table->bigInteger('expected_amount_cents')->nullable(); // cash sales, computed at close
            $table->bigInteger('difference_cents')->nullable();      // closing - (opening + expected)

            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->unique(['tenant_id', 'session_number']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('cash_register_session_id')->nullable()->after('user_id');
            $table->foreign('cash_register_session_id')->references('id')->on('cash_register_sessions')->nullOnDelete();
            $table->index('cash_register_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['cash_register_session_id']);
            $table->dropColumn('cash_register_session_id');
        });

        Schema::dropIfExists('cash_register_sessions');
    }
};
