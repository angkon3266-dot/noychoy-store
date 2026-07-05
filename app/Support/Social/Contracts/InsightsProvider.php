<?php

namespace App\Support\Social\Contracts;

use Illuminate\Support\Carbon;

/**
 * Provider-agnostic READ-ONLY performance data for the Analytics module.
 * Deliberately has no write/manage methods — this platform never edits ads.
 */
interface InsightsProvider
{
    /** Ad performance metrics (spend, ROAS, CTR, CPC, CPM, reach, impressions). */
    public function adInsights(Carbon $from, Carbon $to): array;

    /** Organic/page/IG engagement + follower metrics. */
    public function organicInsights(Carbon $from, Carbon $to): array;

    /** Audience demographics/insights. */
    public function audienceInsights(): array;
}
