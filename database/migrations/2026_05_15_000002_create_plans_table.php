<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modified in-place for issue #9 (S0-E5): replaced single price_cents + billing_period
 * columns with the canonical ERD §1 schema — separate price_monthly_cents / price_yearly_cents,
 * currency column, description, and a composite index on (is_active, sort_order).
 *
 * Safe to modify in-place — no production data exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_monthly_cents');
            $table->unsignedInteger('price_yearly_cents');
            $table->string('currency', 3)->default('USD');
            $table->json('features');
            $table->json('limits');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // ERD §3 — composite index for pricing page ordered listing
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
