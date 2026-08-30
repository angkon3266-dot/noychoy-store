<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Visit;
use App\Support\TrafficSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Re-file traffic that was recorded as "Other website" but came from a channel
 * we can now name.
 *
 * Meta's `{{site_source_name}}` macro resolves to `fb` / `ig` / `an` / `msg`.
 * TrafficSource only matched full platform names, so every tagged ad click fell
 * through to `referral` — the store's Facebook traffic showed up under "Other
 * website" and its ad spend looked like it was buying nothing. The classifier
 * is fixed; this repairs the rows written before the fix, using the referrer
 * host, which is evidence rather than inference.
 *
 * Rows whose referrer was stripped by the app they came from cannot be
 * recovered — there is nothing in them to recover from — and are left alone.
 */
class ReclassifyVisits extends Command
{
    protected $signature = 'visits:reclassify {--dry-run : Report what would change without writing}';

    protected $description = 'Re-file visits and orders logged as "Other website" that have a recognisable referrer';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! Schema::hasTable('visits')) {
            $this->warn('No visits table — nothing to do.');

            return self::SUCCESS;
        }

        $visits = $this->reclassifyVisits($dry);
        $paid = $this->markPaidTraffic($dry);
        $orders = $this->reclassifyOrders($dry);

        $this->newLine();
        $this->info($dry
            ? "Dry run: {$visits} visit(s) would be re-filed, {$paid} marked as paid, {$orders} order(s) re-filed."
            : "Re-filed {$visits} visit(s), marked {$paid} as paid, re-filed {$orders} order(s).");

        if (! $dry && ($visits || $orders)) {
            \Illuminate\Support\Facades\Cache::flush();
            $this->line('Dashboard cache flushed so the panels recompute.');
        }

        return self::SUCCESS;
    }

    protected function reclassifyVisits(bool $dry): int
    {
        $rows = Visit::query()
            ->where(fn ($q) => $q->where('source', 'referral')->orWhereNull('source')->orWhere('source', ''))
            ->whereNotNull('referrer_host')->where('referrer_host', '!=', '')
            ->selectRaw('referrer_host, COUNT(*) as c')
            ->groupBy('referrer_host')->orderByDesc('c')->get();

        $total = 0;

        foreach ($rows as $row) {
            $channel = TrafficSource::fromReferrerHost($row->referrer_host);

            if (! $channel) {
                $this->line(sprintf('  keep  %-28s %6d  (genuinely another website)', $row->referrer_host, $row->c));

                continue;
            }

            $this->line(sprintf('  move  %-28s %6d  → %s', $row->referrer_host, $row->c, TrafficSource::label($channel)));
            $total += (int) $row->c;

            if (! $dry) {
                Visit::query()
                    ->where(fn ($q) => $q->where('source', 'referral')->orWhereNull('source')->orWhere('source', ''))
                    ->where('referrer_host', $row->referrer_host)
                    ->update(['source' => $channel]);
            }
        }

        return $total;
    }

    /**
     * Separate the ad clicks from the organic ones.
     *
     * A referrer host says which platform, never whether money changed hands —
     * so the pass above can only ever land on "Facebook". `utm_campaign` was
     * recorded from the start, and Meta's `{{campaign.id}}` macro resolves to a
     * long numeric id that no human types as a campaign name. That is the same
     * inference the live classifier now makes, so applying it here leaves the
     * history and everything recorded from today agreeing with each other
     * instead of splitting the same ad across two channels.
     */
    protected function markPaidTraffic(bool $dry): int
    {
        $total = 0;

        foreach (['facebook' => 'facebook_ads', 'instagram' => 'instagram_ads', 'google' => 'google_ads'] as $organic => $paid) {
            // Filtered in PHP rather than SQL: SQLite ships no REGEXP, and
            // TrafficSource owns the rule — duplicating it in a WHERE clause is
            // how the two would come to disagree.
            $ids = Visit::query()->where('source', $organic)
                ->whereNotNull('campaign')->where('campaign', '!=', '')
                ->distinct()->pluck('campaign')
                ->filter(fn ($c) => TrafficSource::isPlatformCampaignId($c))
                ->values();

            if ($ids->isEmpty()) {
                continue;
            }

            $count = Visit::where('source', $organic)->whereIn('campaign', $ids)->count();

            $this->line(sprintf('  paid  %-28s %6d  → %s', $organic, $count, TrafficSource::label($paid)));
            $total += $count;

            if (! $dry) {
                Visit::where('source', $organic)->whereIn('campaign', $ids)->update(['source' => $paid]);
            }
        }

        return $total;
    }

    /** The same repair on the copy stamped onto each order. */
    protected function reclassifyOrders(bool $dry): int
    {
        if (! Schema::hasColumn('orders', 'source_channel')) {
            return 0;
        }

        // Only `source_channel`: `source_referrer` is the *last* touch's host,
        // so using it to re-file `first_touch_channel` would put a first visit
        // on the channel that closed the sale weeks later.
        $rows = Order::query()
            ->where('source_channel', 'referral')
            ->whereNotNull('source_referrer')->where('source_referrer', '!=', '')
            ->selectRaw('source_referrer as host, COUNT(*) as c')
            ->groupBy('source_referrer')->get();

        $total = 0;

        foreach ($rows as $row) {
            $channel = TrafficSource::fromReferrerHost($row->host);

            if (! $channel) {
                continue;
            }

            $total += (int) $row->c;

            if (! $dry) {
                Order::query()->where('source_channel', 'referral')->where('source_referrer', $row->host)
                    ->update(['source_channel' => $channel]);
            }
        }

        return $total;
    }
}
