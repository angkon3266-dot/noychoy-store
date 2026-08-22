<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collections — Shopify-style curated product groups.
 *
 * Deliberately NOT categories. A category is the product's taxonomy (a ring is
 * a Ring, and exactly one thing), and it carries the Google feed mapping. A
 * collection cuts across that: "Eid Gifts", "Under ৳2,000", "New this month".
 * Folding those into the category tree would pollute breadcrumbs, the nav and
 * the Meta/Google category mapping.
 *
 * `type` mirrors CustomerSegment: 'smart' populates itself from `rules`,
 * 'manual' uses the picked product list. A smart collection may still pin
 * products via the pivot — those are merged in on top of the rule matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();

            $table->string('type')->default('smart');      // smart | manual
            $table->string('match')->default('all');       // all | any  (smart only)
            $table->json('rules')->nullable();             // [{field, operator, value}]
            $table->string('sort')->default('new');        // matches the catalog sort keys

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            // Offer it as a destination in the menu builder / occasion tiles.
            $table->boolean('show_in_menu')->default(false);

            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('collections');
    }
};
