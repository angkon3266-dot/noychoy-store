<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abandoned_carts', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->nullable()->index();
            $table->string('phone')->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->json('items')->nullable();        // snapshot of cart lines
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->string('last_step')->default('checkout'); // where they dropped off
            $table->boolean('recovered')->default(false);     // did they later order?
            $table->boolean('contacted')->default(false);     // admin followed up
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_carts');
    }
};
