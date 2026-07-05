<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial "story sections" for product pages: per-product sections plus a
 * reusable named template library.
 *
 *  - products.content_sections : this product's own image+text blocks
 *  - content_templates         : saved, reusable section arrangements
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('sections')->nullable(); // [{image, heading, body, layout}]
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('content_sections')->nullable()->after('video_urls');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('content_sections');
        });
        Schema::dropIfExists('content_templates');
    }
};
