<?php

use App\Models\ContentTemplate;
use App\Models\Product;
use App\Support\Storefront\StorySections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-10-24: "keep only one row with videos, if videos not available
 * don't add this. remove the other two for good."
 *
 * Every product had three story rows. Each keeps only the row that plays its
 * video (row 1 when the product has an uploaded clip); a product with no
 * video keeps none. The rows removed are copied to product_story_backups
 * first, so down() can put them back.
 *
 * Written with the query builder, not $product->save(): a save would fire the
 * product observers (catalogue feeds, "price dropped" and stock alerts) for
 * what is only a copy change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_story_backups')) {
            Schema::create('product_story_backups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->json('content_sections');
                $table->timestamp('created_at')->nullable();
            });
        }

        Product::withTrashed()->with('images')->whereNotNull('content_sections')->orderBy('id')
            ->chunkById(100, function ($products) {
                foreach ($products as $product) {
                    $raw = (array) ($product->content_sections ?? []);
                    if (! $raw) {
                        continue;
                    }

                    $rows = ContentTemplate::cleanSections($raw);
                    $resolved = StorySections::resolve($product);
                    $keepAt = collect($resolved)->search(fn ($s) => ($s['media']['type'] ?? null) === 'video');
                    $kept = $keepAt === false ? [] : [$rows[$keepAt]];

                    if ($kept == $raw) {
                        continue;   // already one video row
                    }

                    DB::table('product_story_backups')->insert([
                        'product_id' => $product->id,
                        'content_sections' => json_encode($raw),
                        'created_at' => now(),
                    ]);
                    DB::table('products')->where('id', $product->id)->update([
                        'content_sections' => $kept ? json_encode($kept) : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_story_backups')) {
            return;
        }

        // The oldest backup per product is what it had before this ran.
        DB::table('product_story_backups')->orderBy('id')->get()->unique('product_id')
            ->each(fn ($b) => DB::table('products')->where('id', $b->product_id)->update(['content_sections' => $b->content_sections]));

        Schema::drop('product_story_backups');
    }
};
