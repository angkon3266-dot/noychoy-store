<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // sha256(endpoint) — lets us keep endpoint uniqueness without a long index.
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh');            // client public key (base64url)
            $table->string('auth');              // client auth secret (base64url)
            $table->string('ua')->nullable();    // user-agent snapshot
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
