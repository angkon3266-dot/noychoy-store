<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every compare-at price comes off, at the owner's request (19 Sep 2026).
 *
 * "Remove sitewide compared at price for all products." From today the price a
 * product is struck through against comes from a live offer in Admin → Offers
 * (App\Support\OfferPricing): while one covers a piece it lists at the
 * discounted price with its regular price crossed out, and when the offer goes,
 * so does the crossing-out. A compare-at price typed on the product would argue
 * with that. The owner chose to clear them all but keep the field, so one can
 * still be set by hand later — it shows only while no offer covers the piece.
 *
 * On production that is 32 products (24 of them published, 2 in the bin) and
 * 8 variants. Nothing is lost: each value is copied into
 * `compare_at_price_backups` first, beside the price it stood against, and
 * rolling this migration back puts every one back — except where the field has
 * been filled in again since, which then wins.
 *
 * Query builder, not models: saving 32 products would queue 32 knowledge syncs
 * to change a column the storefront no longer shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compare_at_price_backups', function (Blueprint $table) {
            $table->id();
            // No foreign key: a backup must outlive a product force-deleted later.
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('product_variant_id')->nullable()->index();
            $table->decimal('compare_at_price', 12, 2);
            $table->decimal('price', 12, 2)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $now = now();

        $rows = DB::table('products')->whereNotNull('compare_at_price')->orderBy('id')
            ->get(['id', 'price', 'compare_at_price'])
            ->map(fn ($p) => [
                'product_id' => $p->id,
                'product_variant_id' => null,
                'compare_at_price' => $p->compare_at_price,
                'price' => $p->price,
                'created_at' => $now,
            ])
            ->concat(DB::table('product_variants')->whereNotNull('compare_at_price')->orderBy('id')
                ->get(['id', 'product_id', 'price', 'compare_at_price'])
                ->map(fn ($v) => [
                    'product_id' => $v->product_id,
                    'product_variant_id' => $v->id,
                    'compare_at_price' => $v->compare_at_price,
                    'price' => $v->price,
                    'created_at' => $now,
                ]));

        foreach ($rows->chunk(200) as $chunk) {
            DB::table('compare_at_price_backups')->insert($chunk->values()->all());
        }

        DB::table('products')->whereNotNull('compare_at_price')->update(['compare_at_price' => null]);
        DB::table('product_variants')->whereNotNull('compare_at_price')->update(['compare_at_price' => null]);

        // The filter sidebar's cached facets were built with them.
        \App\Services\StorefrontFilters::bumpVersion();
    }

    public function down(): void
    {
        foreach (DB::table('compare_at_price_backups')->orderBy('id')->get() as $row) {
            $row->product_variant_id
                ? DB::table('product_variants')->where('id', $row->product_variant_id)->whereNull('compare_at_price')
                    ->update(['compare_at_price' => $row->compare_at_price])
                : DB::table('products')->where('id', $row->product_id)->whereNull('compare_at_price')
                    ->update(['compare_at_price' => $row->compare_at_price]);
        }

        Schema::dropIfExists('compare_at_price_backups');

        \App\Services\StorefrontFilters::bumpVersion();
    }
};
