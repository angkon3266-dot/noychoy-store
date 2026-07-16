<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'adjustments')) {
            Schema::table('orders', function (Blueprint $table) {
                // Manual charge/discount lines added by an admin, e.g.
                // [{"label":"Gift wrap","amount":50},{"label":"Loyal customer","amount":-100}]
                $table->json('adjustments')->nullable()->after('discount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'adjustments')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('adjustments'));
        }
    }
};
