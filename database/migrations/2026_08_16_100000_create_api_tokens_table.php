<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens for the storefront's own admin API (and the MCP endpoint on top of
 * it), so an outside agent can work the catalogue the way it works Shopify's.
 *
 * Only a SHA-256 of the token is stored: the plaintext is shown once at
 * creation and is unrecoverable afterwards, so a leaked database row cannot
 * be replayed against the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // human label: "Claude", "Zapier"
            $table->string('token_hash', 64)->unique();   // sha256 hex
            $table->string('prefix', 16);                 // shown in the UI so a token is identifiable
            $table->json('abilities')->nullable();        // null = full access
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
