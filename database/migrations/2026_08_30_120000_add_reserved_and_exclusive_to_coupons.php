<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a coupon could not express before.
 *
 * `reserved_for_phone` — a coupon that belongs to ONE person. `per_customer_limit`
 * only ever capped how many times a code could be used per phone, so a code
 * meant for one customer still worked for anyone who was told it. A thank-you
 * offer texted to a buyer has to be hers alone.
 *
 * `is_exclusive` — a coupon that does not stack. It competes with the quantity,
 * offer, member and personalised discounts, and only the better deal for the
 * customer applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'reserved_for_phone')) {
                $table->string('reserved_for_phone', 20)->nullable()->index()->after('per_customer_limit');
            }
            if (! Schema::hasColumn('coupons', 'is_exclusive')) {
                $table->boolean('is_exclusive')->default(false)->after('free_shipping');
            }
            // Auto-generated codes fill the coupon list; without this the owner
            // cannot tell which order a THANKS-xxxxxx code came from when a
            // customer messages asking why hers will not apply.
            if (! Schema::hasColumn('coupons', 'label')) {
                $table->string('label', 120)->nullable()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            foreach (['reserved_for_phone', 'is_exclusive', 'label'] as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
