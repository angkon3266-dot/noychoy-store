<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->text('message');
            $table->string('direction')->default('out');
            $table->string('status')->nullable();        // ACCEPTD | REJECTD
            $table->string('provider_status')->nullable(); // numeric Status code
            $table->string('message_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
