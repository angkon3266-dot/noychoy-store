<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet credit per mobile number (owner, 2026-11-03: "add money in the wallet
 * for specific mobile number and they can use that during checkout").
 *
 * Keyed on the canonical phone (bd_phone), not on a customer row: the owner
 * credits a number before that person has ever ordered, and most buyers check
 * out as guests who are only ever a phone number.
 *
 * `wallets.balance` is the running figure the checkout locks and spends;
 * `wallet_transactions` is the ledger behind it, one row per movement with
 * the balance it left. On an order, `wallet_amount` is the credit spent on it
 * (already inside `discount` — it is store credit, counted like a discount)
 * and `wallet_refunded_at` says it went back when the order unwound.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->id();
                $table->string('phone', 20)->unique();
                $table->decimal('balance', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
                // Signed: credit and refund are positive, debit and spend negative.
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->string('type', 20);
                $table->string('note', 255)->nullable();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->index(['wallet_id', 'id']);
                $table->index(['order_id', 'type']);
            });
        }

        if (! Schema::hasColumn('orders', 'wallet_amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('wallet_amount', 12, 2)->default(0)->after('points_discount');
                $table->timestamp('wallet_refunded_at')->nullable()->after('wallet_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['wallet_amount', 'wallet_refunded_at']);
        });
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
