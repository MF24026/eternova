<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('occasion')->nullable();
            $table->date('delivery_date')->nullable();
            $table->unsignedInteger('total_amount')->default(0);
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->unsignedInteger('deposit_paid')->default(0);
            $table->enum('status', ['inquiry', 'confirmed', 'in_progress', 'ready', 'delivered', 'cancelled'])->default('inquiry');
            $table->text('special_instructions')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('reservation_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->enum('payment_method', ['cash', 'card', 'transfer']);
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_payments');
        Schema::dropIfExists('reservations');
    }
};
