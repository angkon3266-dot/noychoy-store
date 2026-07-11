<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether an order's stock has been returned to inventory (on cancel),
 * so restocking is idempotent — cancelling twice never double-restocks, and
 * moving an order back out of "cancelled" re-deducts exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('stock_restored')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_restored');
        });
    }
};
