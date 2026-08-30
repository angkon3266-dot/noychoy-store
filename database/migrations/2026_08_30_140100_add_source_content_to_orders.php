<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ad (utm_content) behind an order, alongside the campaign already stored.
 *
 * Without it the dashboard can say "this campaign earned ৳40,000" but never
 * "this *ad* did" — and the ad is the unit you actually scale or switch off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'source_campaign') || Schema::hasColumn('orders', 'source_content')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source_content', 80)->nullable()->after('source_campaign');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'source_content')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('source_content');
        });
    }
};
