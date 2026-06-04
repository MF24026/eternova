<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modified in-place for issue #6 (S0-E2): replaced JSON brand_config / locale_config blobs
 * with the hybrid columns-plus-JSON schema defined in docs/diagrams/sprint-0-erd.md §2.3.
 *
 * Fields promoted to explicit columns are queryable, indexable, and validatable in Form
 * Requests. Fields in brand_extra / locale_extra remain JSON for future extensibility
 * without requiring a new migration.
 *
 * This migration was safe to modify in-place because no production data existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            // Identity
            $table->ulid('id')->primary();
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('email');
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');

            // Brand — explicit queryable columns
            $table->string('business_name');
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->nullable();   // hex: #rrggbb
            $table->string('secondary_color', 7)->nullable();
            $table->string('favicon_url')->nullable();

            // Brand — extensible JSON for secondary fields
            // (tagline, social_links, dark_logo_url, custom_css_vars, webfont_url, etc.)
            $table->json('brand_extra')->nullable();

            // Locale — explicit queryable columns
            $table->string('currency', 3)->default('USD');
            $table->string('country_code', 2)->default('SV');
            $table->string('language', 5)->default('es');
            $table->string('timezone')->default('America/El_Salvador');

            // Locale — extensible JSON for secondary fields
            // (date_format, phone_format, tax_rates_default, decimal_separator, etc.)
            $table->json('locale_extra')->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes per ERD §3
            $table->index('status');
            $table->index(['country_code', 'currency']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
