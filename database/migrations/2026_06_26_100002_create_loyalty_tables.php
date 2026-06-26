<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Points balance on the customer.
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('points')->default(0)->after('total_spent');           // spendable balance
            $table->unsignedInteger('points_lifetime')->default(0)->after('points'); // total ever earned (for tiers)
        });

        // Points ledger — every earn/redeem/adjustment, one row.
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->integer('points');                       // +earned / -redeemed
            $table->string('type');                          // earn_order|earn_review|earn_share|redeem|signup|adjust
            $table->string('description')->nullable();
            $table->nullableMorphs('reference');             // e.g. Order / Review (keeps awards idempotent)
            $table->timestamps();
            $table->index(['customer_id', 'type']);
        });

        // Per-customer offers (admin assigns; shown in the customer's offers panel).
        Schema::create('customer_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('type')->default('percent');      // percent|fixed|free_shipping|points
            $table->decimal('value', 12, 2)->default(0);     // % or ৳ or points
            $table->string('code')->nullable();              // optional promo code to apply at checkout
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'is_active']);
        });

        // Points captured on each order (redeemed at checkout, earned on delivery).
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('points_redeemed')->default(0)->after('discount');
            $table->decimal('points_discount', 12, 2)->default(0)->after('points_redeemed');
            $table->integer('points_earned')->default(0)->after('points_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['points_redeemed', 'points_discount', 'points_earned']));
        Schema::dropIfExists('customer_offers');
        Schema::dropIfExists('point_transactions');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['points', 'points_lifetime']));
    }
};
