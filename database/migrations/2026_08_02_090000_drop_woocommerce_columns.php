<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the WooCommerce import columns.
 *
 * The store no longer runs a WooCommerce migration: the `woo:import` command,
 * its config and its docs are gone. These columns existed only to match a local
 * record back to the row it was imported from, and every one of them is NULL in
 * production — nothing was ever imported through that path, and no code read
 * them for logic.
 *
 * down() restores the exact same nullable+indexed columns. Since the data was
 * entirely NULL, that is a complete rollback, not a partial one.
 */
return new class extends Migration
{
    /** table => column */
    private const COLUMNS = [
        'products' => 'woo_id',
        'categories' => 'woo_id',
        'customers' => 'woo_id',
        'orders' => 'woo_id',
        'product_variants' => 'woo_variation_id',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // The index must go first and in its own statement. Neither driver
            // cleans it up for us: MySQL refuses to drop an indexed column, and
            // SQLite drops the column but leaves the index pointing at nothing
            // ("1 error in index products_woo_id_index after drop column").
            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                $t->dropIndex("{$table}_{$column}_index");
            });

            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->unsignedBigInteger($column)->nullable()->index();
            });
        }
    }
};
