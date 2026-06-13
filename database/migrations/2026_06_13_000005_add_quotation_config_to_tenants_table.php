<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-tenant quotation configuration columns to the tenants table.
 *
 * These columns allow each tenant to customise their quotations workflow
 * without depending on the Settings module.
 *
 *   quotation_tax_rate_bps — default IVA rate in basis points for new quotations.
 *     Staff can override per-quotation; this is only the pre-fill default.
 *     Range 0–9999 bps (max 99.99%). 1300 = 13% (El Salvador), 1900 = 19% (Colombia).
 *
 *   quotation_valid_days — how many calendar days from issue_date until valid_until.
 *     Null/0 means no automatic expiry pre-fill. Default 15 is a sensible starting
 *     point for most florist/gift shop use cases.
 *
 *   quotation_terms — default terms and conditions text. Pre-fills the terms field
 *     on each new quotation; the user can overwrite per-quotation. Null = no default.
 *
 * Both columns are additive — existing tenants get sensible defaults and are
 * not affected operationally by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('quotation_tax_rate_bps')
                ->default(0)
                ->after('reservation_occasions')
                ->comment('Default IVA rate in basis points for new quotations (1300 = 13%); overridable per quotation.');

            $table->unsignedSmallInteger('quotation_valid_days')
                ->default(15)
                ->after('quotation_tax_rate_bps')
                ->comment('Default validity window in days (valid_until = issue_date + N days); overridable per quotation.');

            $table->text('quotation_terms')
                ->nullable()
                ->after('quotation_valid_days')
                ->comment('Default terms and conditions pre-filled on each new quotation. Null = no default.');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['quotation_tax_rate_bps', 'quotation_valid_days', 'quotation_terms']);
        });
    }
};
