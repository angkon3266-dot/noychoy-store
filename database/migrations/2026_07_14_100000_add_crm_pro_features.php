<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Win-back automation cooldown tracking (so we don't re-nudge the same
        // lapsed member every day).
        if (! Schema::hasColumn('customers', 'winback_sent_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->timestamp('winback_sent_at')->nullable()->after('last_order_at');
            });
        }

        // Campaign analytics counters on each notification.
        Schema::table('customer_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_notifications', 'recipients_count')) {
                $table->unsignedInteger('recipients_count')->default(0)->after('audience');
            }
            if (! Schema::hasColumn('customer_notifications', 'clicks')) {
                $table->unsignedInteger('clicks')->default(0)->after('recipients_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropColumn(['recipients_count', 'clicks']);
        });
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('winback_sent_at'));
    }
};
