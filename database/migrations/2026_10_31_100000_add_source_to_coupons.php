<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a coupon came from, when the shop minted it rather than the owner.
 *
 * Only the checkout spin wheel writes it so far ('spin_wheel'): its prizes are
 * one-off codes, so "one prize per phone number" cannot be counted on a single
 * code's usage — it has to be counted across every code the wheel ever minted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('label')->index();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
