<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT on top of an expense (owner, 2026-11-01: "add option to add vat jeta
 * amount er sathe add hobe additionally") — a Meta ad bill of ৳2,500 + 15% VAT
 * cost ৳2,875.
 *
 * `amount` stays what was typed, before VAT; `vat_amount` is worked out from
 * `vat_rate` when the expense is saved, so totals are amount + vat_amount and
 * every existing row (no VAT, 0) reads as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('expenses', 'vat_amount')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('amount');
            $table->decimal('vat_amount', 12, 2)->default(0)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'vat_amount']);
        });
    }
};
