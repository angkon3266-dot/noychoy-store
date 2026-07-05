<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-module install/enable state + per-module settings. Powers the plugin-style
 * install/remove and the registry's enabled() filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_module_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 40)->index();
            $table->boolean('enabled')->default(true);
            $table->timestamp('installed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['meta_connection_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_module_states');
    }
};
