<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->string('phone')->nullable();
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('photos')->nullable();    // array of stored image paths
            $table->boolean('is_verified_buyer')->default(false);
            // pending → awaiting moderation, approved → public, hidden → turned off
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
