<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable version snapshots. One row is written BEFORE every configuration
 * change, capturing the previous + new values (both encrypted) for the affected
 * section, so any change can be reviewed, diffed and rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('section', 40)->index();
            $table->longText('previous_values')->nullable(); // encrypted JSON
            $table->longText('new_values')->nullable();       // encrypted JSON
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_versions');
    }
};
