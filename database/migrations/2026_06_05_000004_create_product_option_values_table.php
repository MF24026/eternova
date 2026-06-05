<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('option_id')
                ->constrained('product_options')
                ->cascadeOnDelete();
            $table->string('value', 100);
            $table->integer('position')->default(0);
            $table->timestamps();

            // Index per ERD §3
            $table->index(['option_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};
