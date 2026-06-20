<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Wompi EnlacePagoRecurrente: gateway_subscription_id already holds the idEnlace;
            // these hold the hosted affiliation URL (urlEnlace) + QR for the tenant UI.
            $table->string('affiliation_url')->nullable()->after('gateway_subscription_id');
            $table->string('affiliation_qr_url')->nullable()->after('affiliation_url');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['affiliation_url', 'affiliation_qr_url']);
        });
    }
};
