<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Style tools (Nov 2026): Shop the look, shareable wishlists, and a cut-out
 * image per product for the earring try-on and the bangle & ring stacker.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A photo with products pinned on it. Each hotspot is {x, y, product_id}
        // with x/y as percentages of the photo, so it holds at any size.
        Schema::create('looks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('caption', 300)->nullable();
            $table->string('image');
            $table->json('hotspots')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // A wishlist someone chose to share. The code is what goes in the
        // link; the visitor token (the love cookie) never leaves the server.
        Schema::create('wishlist_shares', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('visitor_token', 64)->nullable()->index();
            $table->foreignId('customer_id')->nullable()->index();
            $table->string('owner_name', 60)->nullable();
            $table->timestamps();
        });

        // A transparent cut-out of the piece (PNG/WebP), drawn by the try-on
        // and the stacker. Optional — without one they use the main photo.
        Schema::table('products', function (Blueprint $table) {
            $table->string('cutout_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cutout_image');
        });
        Schema::dropIfExists('wishlist_shares');
        Schema::dropIfExists('looks');
    }
};
