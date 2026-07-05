<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central store for editable platform configuration. One row per field, keyed by
 * section + key. Sensitive values are stored Crypt-encrypted (is_encrypted=1).
 * These are applied as runtime overrides over the .env-loaded config at boot —
 * the .env file stays as bootstrap + fallback and is never rewritten for these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->string('section', 40)->index();
            $table->string('key', 80);
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();

            $table->unique(['section', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_configs');
    }
};
