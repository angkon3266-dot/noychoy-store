<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Quantity / bundle offers: [{ "min_qty": 2, "percent": 5 }, ...]
            $table->json('quantity_offers')->nullable()->after('options');
            // Manual product relationships for the PDP.
            $table->json('upsell_ids')->nullable()->after('quantity_offers');     // "You may also like"
            $table->json('cross_sell_ids')->nullable()->after('upsell_ids');      // "Frequently bought together"
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['quantity_offers', 'upsell_ids', 'cross_sell_ids']);
        });
    }
};
