<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item Meta catalog sync state. One row per product (and per variant of a
 * variable product) tracking whether it is currently in sync with the Meta
 * catalog, when it last synced, and the hash of the last-sent payload so we can
 * skip no-op syncs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Null for the parent/simple product; set for a specific variation.
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();

            // The retailer_id we send to Meta (stable external id for this item).
            $table->string('retailer_id')->index();

            $table->string('status', 20)->default('never')->index(); // never|pending|synced|failed|removed
            $table->timestamp('last_synced_at')->nullable();
            $table->string('payload_hash', 64)->nullable(); // sha256 of last payload sent
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_sync_states');
    }
};
