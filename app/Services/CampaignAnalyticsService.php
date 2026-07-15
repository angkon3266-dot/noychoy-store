<?php

namespace App\Services;

use App\Models\CustomerNotification;
use App\Models\Order;

/**
 * Campaign performance for member notifications: reach, clicks, and — the metric
 * that matters — orders + revenue attributed to each send.
 *
 * Attribution: an order counts as a conversion if it was placed by a recipient
 * within ATTRIBUTION_DAYS of the notification going out. For segment sends the
 * recipient set is the exact snapshot pivot; for all-member sends it's any
 * registered member (an approximation, since "all" keeps no per-person pivot).
 */
class CampaignAnalyticsService
{
    public const ATTRIBUTION_DAYS = 7;

    /**
     * Per-notification metrics.
     *
     * @return array{recipients:int, clicks:int, ctr:float, conversions:int, revenue:float, conv_rate:float}
     */
    public function forNotification(CustomerNotification $n): array
    {
        $recipients = (int) $n->recipients_count;
        $clicks = (int) $n->clicks;

        [$conversions, $revenue] = $n->sent_at
            ? $this->conversions($n)
            : [0, 0.0];

        return [
            'recipients' => $recipients,
            'clicks' => $clicks,
            'ctr' => $recipients > 0 ? round($clicks / $recipients * 100, 1) : 0.0,
            'conversions' => $conversions,
            'revenue' => $revenue,
            'conv_rate' => $recipients > 0 ? round($conversions / $recipients * 100, 1) : 0.0,
        ];
    }

    /**
     * Distinct converting customers + their revenue within the attribution window.
     *
     * @return array{0:int, 1:float}
     */
    protected function conversions(CustomerNotification $n): array
    {
        $from = $n->sent_at;
        $to = $n->sent_at->copy()->addDays(self::ATTRIBUTION_DAYS);

        // Last-touch attribution: cap the window at the next campaign that went
        // out, so an order is credited to only the most recent campaign before it
        // (no double-counting the same order across overlapping campaigns).
        $nextSentAt = CustomerNotification::whereNotNull('sent_at')
            ->where('sent_at', '>', $n->sent_at)
            ->min('sent_at');
        if ($nextSentAt) {
            $next = \Illuminate\Support\Carbon::parse($nextSentAt);
            if ($next->lt($to)) {
                $to = $next;
            }
        }

        // Half-open interval [from, to) so a boundary order isn't counted twice.
        $q = Order::query()
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        if ($n->audience === 'segment') {
            // Exact recipients snapshot.
            $q->whereIn('customer_id', function ($sub) use ($n) {
                $sub->from('customer_notification_recipients')
                    ->select('customer_id')
                    ->where('customer_notification_id', $n->id);
            });
        } else {
            // All-member send: attribute to any registered member.
            $q->whereIn('customer_id', fn ($sub) => $sub->from('customers')->select('id')->whereNotNull('password'));
        }

        $conversions = (int) (clone $q)->distinct('customer_id')->count('customer_id');
        $revenue = (float) (clone $q)->sum('total');

        return [$conversions, $revenue];
    }

    /** Roll-up across every sent campaign (for the summary header). */
    public function summary()
    {
        $sent = CustomerNotification::whereNotNull('sent_at')->get();
        $reach = $sent->sum('recipients_count');
        $clicks = $sent->sum('clicks');
        $conversions = 0;
        $revenue = 0.0;
        foreach ($sent as $n) {
            [$c, $r] = $this->conversions($n);
            $conversions += $c;
            $revenue += $r;
        }

        return [
            'campaigns' => $sent->count(),
            'reach' => (int) $reach,
            'clicks' => (int) $clicks,
            'conversions' => $conversions,
            'revenue' => $revenue,
        ];
    }
}
