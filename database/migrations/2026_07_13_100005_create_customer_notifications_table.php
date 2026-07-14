<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('announcement');   // announcement|new_arrival|preorder|system
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();                  // CTA link
            $table->string('cta_label')->nullable();
            $table->string('icon', 16)->nullable();             // emoji
            $table->string('audience')->default('all');         // all (segments in Phase 3)
            $table->timestamp('scheduled_at')->nullable();      // null = send now
            $table->timestamp('sent_at')->nullable();           // set when delivered
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sent_at']);
            $table->index(['scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
    }
};
