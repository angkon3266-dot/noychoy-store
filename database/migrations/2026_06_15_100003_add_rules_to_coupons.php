<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Scope: which items the coupon applies to.
            $table->string('applies_to')->default('all')->after('value'); // all | categories | products
            $table->json('category_ids')->nullable()->after('applies_to');
            $table->json('product_ids')->nullable()->after('category_ids');
            $table->boolean('exclude_sale_items')->default(false)->after('product_ids');
            // Quantity gates (counts only eligible items).
            $table->unsignedInteger('min_qty')->nullable()->after('min_order');
            $table->unsignedInteger('max_qty')->nullable()->after('min_qty');
            // Usage limits.
            $table->unsignedInteger('per_customer_limit')->nullable()->after('usage_limit');
            // Free shipping coupon.
            $table->boolean('free_shipping')->default(false)->after('per_customer_limit');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn([
                'applies_to', 'category_ids', 'product_ids', 'exclude_sale_items',
                'min_qty', 'max_qty', 'per_customer_limit', 'free_shipping',
            ]);
        });
    }
};
