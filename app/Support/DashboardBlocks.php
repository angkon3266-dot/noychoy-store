<?php

namespace App\Support;

/**
 * Every card on the admin dashboard, as a block the owner can move.
 *
 * Owner, 2026-09-18: "I need to be able to move around the analytics, top,
 * bottom as per my needs." Until then the dashboard was one long Blade file
 * whose cards sat where the developer put them, and the only choice the ⚙
 * button offered was which of four deep-analysis groups to show at all. She
 * reads it on her phone, so "top" and "bottom" are a long way apart: the card
 * she checks first every morning belongs at the top for HER, and that is a
 * different card from the one another admin checks first.
 *
 * This registry is the one list of what a block is. Each key names a partial
 * in resources/views/admin/dashboard/blocks/{key}.blade.php that renders
 * exactly one card from the same variables the page gets, and the page draws
 * them in whatever order the admin has saved — DashboardLayout resolves that
 * order — inside a single CSS grid, so a block's width is the only layout it
 * carries. The order of BLOCKS is the default order, and a block added here
 * later shows up for every admin at this position without a reset, because
 * the resolver slots unknown-to-the-saved-order keys back in by default.
 *
 *  - title         The name on the block's handle in arrange mode, and on the
 *                  placeholder a hidden block leaves so it can be put back.
 *  - span          How wide, as a share of the grid: third, half, two_thirds
 *                  or full. On a phone every block is full width regardless.
 *  - needs         Which $deep analytics key the block reads. The controller
 *                  computes a key only when a VISIBLE block needs it, so a
 *                  hidden funnel costs no visits-table scan. Several keys are
 *                  comma-separated. Absent means the block reads only the base
 *                  figures every page computes.
 *  - legacy_panel  The old ⚙ group (DashboardController::PANELS) the block
 *                  belonged to. An admin who never saved a layout still gets
 *                  the store-wide choice honoured through this.
 */
final class DashboardBlocks
{
    /**
     * Grid width per span. The grid is 6 columns from md and 12 from lg;
     * below md it is one column and every span is simply the row.
     */
    public const SPANS = [
        // One column below lg, as the page always was: a six-column tablet
        // tier left orphaned half-width cards (2026-09-18 review).
        'third' => 'lg:col-span-4',
        'half' => 'lg:col-span-6',
        'two_thirds' => 'lg:col-span-8',
        'full' => 'col-span-full',
    ];

    /**
     * In default order. Spans reproduce the layout the page had on
     * 2026-09-18, when every card was placed by hand.
     *
     * The blocks that read App\Services\DashboardInsights were added the same
     * day, after the owner asked to "add other analytical info on the
     * dashboard". Each is slotted where a never-arranged dashboard reads best
     * — the call list as the first full row under the chart because it is
     * the morning's work (it cannot sit between the key figures and the
     * chart: the layout resolver's tests pin those two as neighbours, and an
     * admin who wants it first moves it there in one tap), the three
     * money-and-stock cards in one row after it, the discount and chat/SMS
     * cards beside Revenue & profit which they explain, and leads and the
     * order clock with the customer cards. An admin who has already arranged
     * gets them in these same places (DashboardLayout slots a key it has
     * never seen in after its registry neighbour), and can move or hide them
     * like any other. None belongs to an old ⚙ panel, so the store-wide
     * `dashboard_panels` setting leaves them shown.
     *
     * @var array<string, array{title:string, span:string, needs?:string, legacy_panel?:string}>
     */
    public const BLOCKS = [
        'kpi_tiles' => ['title' => 'Key figures', 'span' => 'full'],
        'sales_chart' => ['title' => 'Sales', 'span' => 'two_thirds'],
        'orders_by_status' => ['title' => 'Orders by status', 'span' => 'third'],
        'call_list' => ['title' => 'Who to call today', 'span' => 'full', 'needs' => 'callList'],
        'cash_at_courier' => ['title' => 'Cash with the courier', 'span' => 'third', 'needs' => 'cashAtCourier'],
        'delivery_speed' => ['title' => 'Delivery speed', 'span' => 'third', 'needs' => 'deliverySpeed'],
        'stock_health' => ['title' => 'Cash in stock', 'span' => 'third', 'needs' => 'stockHealth'],
        // Was "Top products" (units sold by name) until 2026-09-18; the key
        // is kept so every saved layout keeps its place for the card.
        'top_products' => ['title' => 'Top earners', 'span' => 'half', 'needs' => 'topEarners,earnersByCategory'],
        'top_categories' => ['title' => 'Best-selling categories', 'span' => 'half'],
        'low_stock' => ['title' => 'Low stock alerts', 'span' => 'half'],
        'top_customers' => ['title' => 'Top customers', 'span' => 'half'],
        'most_loved' => ['title' => 'Most loved products', 'span' => 'full'],
        'messages' => ['title' => 'New messages', 'span' => 'full'],
        'recent_orders' => ['title' => 'Recent orders', 'span' => 'full'],
        'profit' => ['title' => 'Revenue & profit', 'span' => 'full', 'needs' => 'profit', 'legacy_panel' => 'profit'],
        'discount_leakage' => ['title' => 'Where the discounts go', 'span' => 'half', 'needs' => 'discountLeakage'],
        'assistant_sms' => ['title' => 'Chat & SMS', 'span' => 'half', 'needs' => 'assistantAndSms'],
        'funnel' => ['title' => 'Conversion funnel', 'span' => 'two_thirds', 'needs' => 'funnel', 'legacy_panel' => 'funnel'],
        'sources' => ['title' => 'Where visitors come from', 'span' => 'third', 'needs' => 'sources,channelEconomics', 'legacy_panel' => 'funnel'],
        'traffic_over_time' => ['title' => 'Traffic & conversion over time', 'span' => 'full', 'needs' => 'series', 'legacy_panel' => 'funnel'],
        // Polled after the page loads (DashboardController::live), so it needs
        // nothing computed up front.
        'live_visitors' => ['title' => 'On the site right now', 'span' => 'half', 'legacy_panel' => 'funnel'],
        'viewed_not_sold' => ['title' => 'Viewed but never bought', 'span' => 'half', 'needs' => 'viewedNotSold', 'legacy_panel' => 'funnel'],
        'ads' => ['title' => 'Ads & campaigns', 'span' => 'full', 'needs' => 'ads', 'legacy_panel' => 'funnel'],
        'retention' => ['title' => 'Customers & retention', 'span' => 'full', 'needs' => 'retention', 'legacy_panel' => 'retention'],
        'lead_recovery' => ['title' => 'Leads & recovery', 'span' => 'third', 'needs' => 'leadRecovery'],
        'order_clock' => ['title' => 'When customers buy', 'span' => 'third', 'needs' => 'orderClock'],
        'delivery_outcomes' => ['title' => 'Delivery outcomes', 'span' => 'third', 'needs' => 'operations', 'legacy_panel' => 'operations'],
        'running_out' => ['title' => 'Running out soon', 'span' => 'third', 'needs' => 'operations', 'legacy_panel' => 'operations'],
        'dead_stock' => ['title' => 'Dead stock', 'span' => 'third', 'needs' => 'operations', 'legacy_panel' => 'operations'],
    ];

    /** @return array<string, array{title:string, span:string, needs?:string, legacy_panel?:string}> */
    public static function all(): array
    {
        return self::BLOCKS;
    }

    /** The keys in default order. @return string[] */
    public static function keys(): array
    {
        return array_keys(self::BLOCKS);
    }

    /** @return array{title:string, span:string, needs?:string, legacy_panel?:string}|null */
    public static function find(string $key): ?array
    {
        return self::BLOCKS[$key] ?? null;
    }

    /** The Tailwind classes that give a span its width; an unknown span is a full row. */
    public static function spanClass(string $span): string
    {
        return self::SPANS[$span] ?? self::SPANS['full'];
    }

    /**
     * The $deep keys the given blocks read, deduplicated.
     *
     * @param  string[]  $keys
     * @return string[]
     */
    public static function needsOf(array $keys): array
    {
        $needed = [];

        foreach ($keys as $key) {
            foreach (preg_split('/[\s,]+/', (string) (self::BLOCKS[$key]['needs'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $need) {
                $needed[$need] = true;
            }
        }

        return array_keys($needed);
    }
}
