<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Per-unit transportation / packaging / inbound cost, on top of cost_price.
            $table->decimal('transport_cost', 12, 2)->nullable()->after('cost_price');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot the unit cost at sale time so historical margin stays accurate
            // even if the product's cost changes later.
            $table->decimal('cost_price', 12, 2)->nullable()->after('price');
            $table->decimal('transport_cost', 12, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('transport_cost');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'transport_cost']);
        });
    }
};
