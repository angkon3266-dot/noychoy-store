<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'price_drop_notified_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('price_drop_notified_at')->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'price_drop_notified_at')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('price_drop_notified_at'));
        }
    }
};
