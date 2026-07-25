<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each order came from. Two touches are kept because they answer
 * different questions: first touch says which channel *found* this customer,
 * last touch says which one closed the sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'source_channel')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source_channel', 24)->nullable()->after('source');
            $table->string('source_campaign', 80)->nullable()->after('source_channel');
            $table->string('source_referrer', 120)->nullable()->after('source_campaign');
            $table->string('first_touch_channel', 24)->nullable()->after('source_referrer');
            $table->string('landing_path', 255)->nullable()->after('first_touch_channel');

            $table->index(['created_at', 'source_channel']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'source_channel']);
            $table->dropColumn([
                'source_channel', 'source_campaign', 'source_referrer',
                'first_touch_channel', 'landing_path',
            ]);
        });
    }
};
