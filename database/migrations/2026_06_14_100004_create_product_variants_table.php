<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable()->index();
            // { "Size": "6", "Color": "Gold" }
            $table->json('attributes');
            $table->decimal('price', 12, 2)->nullable(); // overrides product price when set
            $table->integer('stock_quantity')->default(0);
            $table->foreignId('image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->unsignedBigInteger('woo_variation_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
