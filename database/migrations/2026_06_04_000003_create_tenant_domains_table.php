<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26);
            $table->string('domain')->unique();
            $table->enum('status', ['pending', 'verified', 'failed'])->default('pending');
            $table->string('verification_token');
            $table->timestamp('verified_at')->nullable();
            $table->enum('ssl_status', ['pending', 'active', 'expired'])->default('pending');
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            // Primary lookup: list of custom domains belonging to a tenant + their status
            $table->index(['tenant_id', 'status']);

            // Job index: SSL renewal worker queries by expiry date
            $table->index('ssl_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
