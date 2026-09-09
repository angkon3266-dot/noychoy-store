<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per follow-up attempt on a lead.
     *
     * The single `contacted` boolean could only ever say "somebody did
     * something, once". It could not say who called, what was said, that the
     * number was wrong, or that she asked to be rung back after Maghrib —
     * so the second person to pick up the lead started from nothing.
     */
    public function up(): void
    {
        Schema::create('abandoned_cart_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abandoned_cart_id')->constrained()->cascadeOnDelete();
            // Nullable: the SMS automation logs here too, and nobody sent it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);          // call | whatsapp | sms | note
            $table->string('outcome', 24)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['abandoned_cart_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_cart_contacts');
    }
};
