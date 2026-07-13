<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_offers', function (Blueprint $table) {
            // null = usable any number of times until it expires; N = capped at N uses.
            $table->unsignedInteger('max_redemptions')->nullable()->after('redeemed_at');
            $table->unsignedInteger('redemptions')->default(0)->after('max_redemptions');
        });

        // Existing offers were single-use; preserve that, and reflect prior use.
        \DB::table('customer_offers')->update(['max_redemptions' => 1]);
        \DB::table('customer_offers')->whereNotNull('redeemed_at')->update(['redemptions' => 1]);
    }

    public function down(): void
    {
        Schema::table('customer_offers', function (Blueprint $table) {
            $table->dropColumn(['max_redemptions', 'redemptions']);
        });
    }
};
