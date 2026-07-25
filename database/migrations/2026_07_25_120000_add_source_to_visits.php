<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('visits', 'source')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            // Resolved channel (see App\Support\TrafficSource) plus the campaign
            // behind it, so the dashboard can group by something meaningful
            // instead of a pile of raw referrer hosts.
            $table->string('source', 24)->nullable()->after('referrer_host');
            $table->string('campaign', 80)->nullable()->after('source');

            $table->index(['created_at', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'source']);
            $table->dropColumn(['source', 'campaign']);
        });
    }
};
