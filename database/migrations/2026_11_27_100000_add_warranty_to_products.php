<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short warranty line per product ("1-Year Warranty"), shown as a badge
 * beside the price (owner, 2026-11-27 — the moissanite line carries one).
 * Empty = no badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'warranty')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('warranty', 60)->nullable()->after('pdp_points');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'warranty')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('warranty');
            });
        }
    }
};
