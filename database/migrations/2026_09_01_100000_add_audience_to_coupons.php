<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons that apply themselves, to an audience the owner chooses.
 *
 * Until now a coupon had to be typed. The only thing that applied itself to a
 * named person was `CustomerOffer`, and that resolves through `auth('customer')`
 * — on this store 632 of 636 customers have no password and check out as
 * guests, so it reached four people.
 *
 * The audience is matched on the phone entered at checkout, which is the only
 * identity a cash-on-delivery shop actually has:
 *
 *   all    — every order
 *   phones — a list in `coupon_recipients` (one person, a batch, a segment, or
 *            numbers that have never ordered yet)
 *   rule   — a standing condition evaluated per shopper (first order, N+ orders,
 *            spend, lapsed), so it covers people who do not exist yet
 *
 * Everything downstream is untouched: an auto-applied coupon is written to
 * `orders.coupon_code` like a typed one, so usage counts, per-customer limits
 * and the row lock in PlaceOrder all keep working as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'auto_apply')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->boolean('auto_apply')->default(false)->after('is_active');
                $table->string('audience', 16)->default('all')->after('auto_apply');
                $table->json('audience_rules')->nullable()->after('audience');

                // The cart asks "which coupons apply themselves?" on every
                // checkout render; without this that is a full table scan.
                $table->index(['auto_apply', 'is_active'], 'coupons_auto_active_idx');
            });
        }

        if (! Schema::hasTable('coupon_recipients')) {
            Schema::create('coupon_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
                // Canonical 01XXXXXXXXX, the same form Customer::phone and
                // Order::customer_phone store — matching is a plain equality.
                $table->string('phone', 20);
                // Kept only so the admin list reads as people rather than
                // digits. Null for numbers pasted in without one.
                $table->string('name', 120)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['coupon_id', 'phone']);
                $table->index('phone');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_recipients');

        if (Schema::hasColumn('coupons', 'auto_apply')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropIndex('coupons_auto_active_idx');
                $table->dropColumn(['auto_apply', 'audience', 'audience_rules']);
            });
        }
    }
};
