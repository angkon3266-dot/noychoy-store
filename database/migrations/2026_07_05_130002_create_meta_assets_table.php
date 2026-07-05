<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalised store for the assets a connection can act on: catalogs, pages,
 * Instagram accounts, pixels, ad accounts. Replaces scattered id columns and
 * lets each module select the asset(s) it needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index(); // catalog|page|instagram|pixel|ad_account
            $table->string('external_id');
            $table->string('name')->nullable();
            $table->longText('asset_token')->nullable(); // encrypted (pages carry their own token)
            $table->json('meta')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamps();

            $table->unique(['meta_connection_id', 'type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_assets');
    }
};
