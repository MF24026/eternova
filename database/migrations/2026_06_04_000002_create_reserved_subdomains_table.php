<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_subdomains', function (Blueprint $table): void {
            $table->id();
            $table->string('subdomain', 63)->unique();
            $table->string('category');
            $table->timestamps();

            // Used for bulk listing / reporting by category (e.g. pruning profanity list)
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_subdomains');
    }
};
