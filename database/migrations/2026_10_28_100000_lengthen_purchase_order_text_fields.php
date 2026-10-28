<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping details and supplier links outgrow VARCHAR(255): a pasted shipping
 * address/tracking note, or an Alibaba link/image URL with its query string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('courier_name')->nullable()->change();
            $table->text('courier_tracking')->nullable()->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->text('product_link')->nullable()->change();
            $table->text('image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('courier_name')->nullable()->change();
            $table->string('courier_tracking')->nullable()->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('product_link')->nullable()->change();
            $table->string('image_url')->nullable()->change();
        });
    }
};
