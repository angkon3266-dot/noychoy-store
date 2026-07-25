<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('push_subscriptions', 'audience')) {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            // 'customer' = a shopper's browser, 'admin' = a staff device that
            // wants new-order alerts. Keeps staff devices out of marketing sends.
            $table->string('audience', 16)->default('customer')->after('customer_id');
            $table->foreignId('user_id')->nullable()->after('audience')->constrained()->nullOnDelete();
            $table->string('label')->nullable()->after('ua');   // "Shamim's iPhone"

            $table->index(['audience', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['audience', 'user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['audience', 'label']);
        });
    }
};
