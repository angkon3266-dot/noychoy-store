<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the ad tag, so "which ad sent this person" is answerable.
 *
 * `campaign` alone (utm_campaign) says which campaign; a campaign usually holds
 * several ads, and the ad is the thing you turn off. Meta hands all of these
 * over for free through its dynamic URL parameters — see docs/ANALYTICS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visits') || Schema::hasColumn('visits', 'medium')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            // utm_medium — "paid_social", "cpc", "email"… what kind of link it was.
            $table->string('medium', 40)->nullable()->after('campaign');
            // utm_content — conventionally the ad/creative name.
            $table->string('content', 80)->nullable()->after('medium');
            // The platform's own numeric ad id, when the link carries one.
            $table->string('ad_id', 40)->nullable()->after('content');

            // The campaigns/ads panel groups by campaign inside a date window.
            $table->index(['created_at', 'campaign'], 'visits_created_campaign_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('visits') || ! Schema::hasColumn('visits', 'medium')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_created_campaign_idx');
            $table->dropColumn(['medium', 'content', 'ad_id']);
        });
    }
};
