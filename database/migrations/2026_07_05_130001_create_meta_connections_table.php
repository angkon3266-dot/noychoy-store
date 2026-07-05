<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token Manager core. One row per provider connection (one per install today;
 * the `provider` column + FK-ready shape allow multiple providers/connections
 * later without schema changes). Tokens are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('meta')->index();
            $table->longText('access_token')->nullable();   // encrypted
            $table->longText('refresh_token')->nullable();   // encrypted (Meta: usually none)
            $table->timestamp('token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->string('business_id')->nullable();
            $table->string('business_name')->nullable();
            $table->string('health_status', 30)->default('disconnected'); // ok|expiring|expired|needs_reconnect|disconnected
            $table->timestamp('last_health_at')->nullable();
            $table->timestamps();

            $table->unique('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_connections');
    }
};
