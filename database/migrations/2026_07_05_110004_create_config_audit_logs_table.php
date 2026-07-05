<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fine-grained audit trail for the configuration module: every save, restore,
 * import, connection test and failed security confirmation, with actor, IP,
 * browser, a masked diff, and success/failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('action', 40)->index();  // save|restore|import|export|test|security_failed|backup
            $table->string('section', 40)->nullable();
            $table->json('detail')->nullable();       // masked change summary
            $table->boolean('success')->default(true)->index();
            $table->string('message')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_audit_logs');
    }
};
