<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every Meta catalog sync attempt (create/update/
 * delete/refresh) with the outcome, Meta's raw response and any API error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->string('retailer_id')->nullable()->index();
            $table->string('product_name')->nullable(); // denormalised so log survives product deletion
            $table->string('action', 30)->index();      // create|update|delete|refresh|test|sync_all
            $table->string('status', 20)->index();      // success|failed|skipped
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('execution_ms')->nullable();

            $table->json('meta_response')->nullable();
            $table->text('api_error')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_sync_logs');
    }
};
