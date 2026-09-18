<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers that may not order (owner, 2026-09-18).
 *
 * One row per canonical `bd_phone()` number, so every way of typing it — with
 * spaces, dashes, +880, 880 or none of those — lands on the same row. The
 * reason is free text because the owner knows why and nobody else needs to
 * parse it; `blocked_by` is kept so a number can be argued about later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blocked_phones')) {
            return;
        }

        Schema::create('blocked_phones', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('reason', 200)->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_phones');
    }
};
