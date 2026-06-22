<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_loves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_token', 64);
            $table->timestamps();
            $table->unique(['product_id', 'visitor_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_loves');
    }
};
