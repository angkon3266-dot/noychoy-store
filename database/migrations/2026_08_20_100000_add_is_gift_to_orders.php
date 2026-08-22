<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'is_gift')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Ticked at checkout ("This is a gift"). The message the buyer
            // writes goes into card_message — the same column the printed
            // thank-you card already reads from, so the packing flow and the
            // card printer need no new plumbing.
            $table->boolean('is_gift')->default(false)->after('card_message');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_gift');
        });
    }
};
