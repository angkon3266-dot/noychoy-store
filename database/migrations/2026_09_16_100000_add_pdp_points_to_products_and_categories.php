<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product or category can carry its own "why buy from us" list for the
 * product page. Null means inherit (product → category → store-wide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->json('pdp_points')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('pdp_points')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('pdp_points'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('pdp_points'));
    }
};
