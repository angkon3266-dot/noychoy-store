<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight first-party traffic log: one row per storefront pageview plus a
 * few funnel events (cart add, checkout start). Powers the dashboard's visitor
 * count and conversion funnel without an external analytics provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visits')) {
            return;
        }

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            // Cookie-based visitor id (not personal data; no IP stored).
            $table->string('visitor_token', 40)->index();
            $table->string('event', 20)->default('page');   // page|product|cart_add|checkout_start
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['created_at', 'event']);
            $table->index(['event', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
