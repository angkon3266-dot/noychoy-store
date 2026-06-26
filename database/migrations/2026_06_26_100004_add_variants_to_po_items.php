<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('target_price', 12, 2)->nullable()->after('unit_cost'); // negotiation target (USD)
            $table->json('attribute_names')->nullable()->after('size');             // e.g. ["Logo","Color"]
            $table->json('variants')->nullable()->after('attribute_names');         // [{attrs:{Logo:"..",Color:".."},qty:40}]
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['target_price', 'attribute_names', 'variants']);
        });
    }
};
