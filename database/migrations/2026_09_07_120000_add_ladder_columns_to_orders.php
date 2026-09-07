<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The reward-ladder rung this order reached and what it unlocked,
            // kept on the order so a later change to the ladder never rewrites
            // what the customer was promised on the day.
            $table->unsignedTinyInteger('ladder_tier')->default(0)->after('member_discount');
            $table->json('ladder_rewards')->nullable()->after('ladder_tier');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ladder_tier', 'ladder_rewards']);
        });
    }
};
