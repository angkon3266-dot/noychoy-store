<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer can be given a deadline (owner, 20 Sep 2026: "I want to be able to
 * add a countdown here so customer knows it's ending soon").
 *
 * The countdown is only honest if the offer really stops, so `ends_at` is what
 * the shop reads, not just what it prints: Offer::scopeActive() drops the offer
 * the moment it passes, which takes the discounted prices, the product-page
 * note, the checkout discount and the deal card with it. Empty means the offer
 * runs until the owner pauses it, exactly as before.
 *
 * Stored in UTC like every timestamp; the admin types Bangladesh time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('ends_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('ends_at');
        });
    }
};
