<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'card_message')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Per-order override for the printed thank-you card. Null means
            // "use the new-customer / repeat-customer default template".
            $table->text('card_message')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('card_message');
        });
    }
};
