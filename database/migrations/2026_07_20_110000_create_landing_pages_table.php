<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone marketing landing pages (/lp/{slug}) built from the same block
 * system as the homepage, plus landing-specific blocks (hero CTA, countdown,
 * benefits, FAQ, sticky bar).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('landing_pages')) {
            return;
        }

        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->boolean('is_published')->default(false);
            // Products this page sells (buy box / product blocks).
            $table->json('product_ids')->nullable();
            $table->json('blocks')->nullable();
            // Chrome + SEO
            $table->boolean('show_header')->default(true);
            $table->boolean('show_footer')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('og_image')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
