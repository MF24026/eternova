<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->foreignId('cash_register_session_id')->constrained()->cascadeOnDelete();

            // Who registered the movement.
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // in = ingreso (cash added), out = retiro (cash removed).
            $table->enum('type', ['in', 'out']);
            $table->bigInteger('amount_cents'); // always positive; type carries the sign
            $table->string('reason', 255);

            $table->timestamps();

            $table->index(['tenant_id', 'cash_register_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
