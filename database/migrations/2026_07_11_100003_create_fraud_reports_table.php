<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caches the courier fraud-check result per phone number. Populated on demand
 * ("Check fraud" on the order details page) so the orders list can flag risky
 * customers yellow without re-running the slow multi-courier lookup each render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_reports', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->json('payload')->nullable();          // full package response
            $table->unsignedInteger('total_deliveries')->default(0);
            $table->unsignedInteger('total_success')->default(0);
            $table->unsignedInteger('total_cancel')->default(0);
            $table->decimal('success_ratio', 5, 2)->nullable();
            $table->decimal('cancel_ratio', 5, 2)->nullable();
            $table->boolean('is_risky')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_reports');
    }
};
