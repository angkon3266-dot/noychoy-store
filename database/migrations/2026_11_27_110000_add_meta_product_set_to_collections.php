<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each collection becomes a product set in the Meta catalogue, the way
 * Shopify's Facebook channel turns collections into sets, so a carousel or
 * catalogue ad can pick it without building the set by hand (owner,
 * 2026-11-27). See MetaProductSetSync.
 *
 * meta_sync           the owner's per-collection switch (on by default)
 * meta_product_set_id the set Meta gave back, so later saves update it
 * meta_set_hash       name + filter last sent, so unchanged sets skip the API
 * meta_synced_at / meta_sync_error  what the admin form shows
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('collections', 'meta_product_set_id')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->boolean('meta_sync')->default(true)->after('show_in_menu');
                $table->string('meta_product_set_id', 40)->nullable()->after('meta_sync');
                $table->string('meta_set_hash', 64)->nullable()->after('meta_product_set_id');
                $table->unsignedInteger('meta_set_count')->nullable()->after('meta_set_hash');
                $table->timestamp('meta_synced_at')->nullable()->after('meta_set_count');
                $table->string('meta_sync_error', 500)->nullable()->after('meta_synced_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('collections', 'meta_product_set_id')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->dropColumn(['meta_sync', 'meta_product_set_id', 'meta_set_hash', 'meta_set_count', 'meta_synced_at', 'meta_sync_error']);
            });
        }
    }
};
