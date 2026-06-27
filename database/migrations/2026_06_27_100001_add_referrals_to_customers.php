<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('referral_code')->nullable()->unique()->after('points_lifetime');
            $table->foreignId('referred_by')->nullable()->after('referral_code')->constrained('customers')->nullOnDelete();
            $table->boolean('referral_rewarded')->default(false)->after('referred_by'); // first-order reward paid?
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn(['referral_code', 'referral_rewarded']);
        });
    }
};
