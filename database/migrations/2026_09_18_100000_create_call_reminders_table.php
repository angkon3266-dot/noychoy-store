<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A number to ring back, and what they were asking about.
 *
 * The owner, 2026-09-17: "create a reminder option where I can add customer
 * lead phone number along with items to call later". A lead who says "call me
 * this evening" or "I'll decide on Friday" used to live on a scrap of paper or
 * in her head — and the sale went with it when she forgot. Each row is one
 * call to make: who, which pieces, when, and — once it is made — how it went.
 *
 *  - phone          Canonical 01XXXXXXXXX (bd_phone), the form customers and
 *                   orders match on, so a reminder can find its customer.
 *  - customer_id    The customer whose number it is, when the shop knows them.
 *  - abandoned_cart_id
 *                   The lead it was made from, via "Remind me to call".
 *  - items          A snapshot, the same shape as an abandoned cart's:
 *                   [{product_id, variant_id|null, name, qty, price}]. It is
 *                   re-read against the live catalogue when an order is made.
 *  - due_at         When to call, stored in UTC like every other timestamp.
 *  - done_at        Null while the call is still to make.
 *  - order_id       Set when "Create order" on the reminder became a sale.
 *
 * Every link is nullOnDelete: a reminder outlives the lead, the order or the
 * staff account it mentions, because the call still has to be made.
 *
 * (done_at, due_at) is the index the sidebar badge and the bell read on every
 * admin page load: "not done, and due by now".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('name', 120)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('abandoned_cart_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('due_at')->index();
            $table->dateTime('done_at')->nullable();
            $table->string('outcome', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['done_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_reminders');
    }
};
