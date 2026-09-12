<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which lead an order came from, when it was converted by hand.
     *
     * A cart flipping to "Recovered" only ever said that *an* order arrived for
     * that phone — it could not say which one, so there was no way to open the
     * sale a chased lead actually turned into, or to tell a rescued cart apart
     * from a customer who happened to come back on their own.
     *
     * Null for the overwhelming majority of orders: nothing links a storefront
     * checkout to a lead beyond the phone number it already matches on.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('abandoned_cart_id')->nullable()->after('source')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['abandoned_cart_id']);
            $table->dropColumn('abandoned_cart_id');
        });
    }
};
