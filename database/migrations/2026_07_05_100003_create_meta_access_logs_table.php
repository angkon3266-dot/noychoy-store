<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security audit trail for the secondary password wall protecting the Meta
 * Integration module. Records every unlock attempt (success or failure).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('event', 30); // unlock_success|unlock_failed|locked_out|password_changed
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_access_logs');
    }
};
