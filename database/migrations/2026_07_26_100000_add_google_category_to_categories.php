<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google product taxonomy per category, for the Meta/Google catalogue feeds.
 * Meta uses it to classify items; leaving it blank shows "Google product
 * category: Missing" in Commerce Manager and weakens automatic targeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'google_category')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->string('google_category', 120)->nullable()->after('product_template');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('google_category');
        });
    }
};
