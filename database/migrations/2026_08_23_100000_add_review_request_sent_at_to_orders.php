<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'review_request_sent_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Stamped by `reviews:request` the moment it queues the ask.
            // Null = never asked. An admin toggling delivered → shipped →
            // delivered can therefore never make the shop pay for a second SMS.
            $table->timestamp('review_request_sent_at')->nullable()->after('stock_restored');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('review_request_sent_at');
        });
    }
};
