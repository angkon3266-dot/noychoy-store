<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Log retention (days)
     |--------------------------------------------------------------------------
     |
     | How long each append-only table is kept before `logs:prune` deletes older
     | rows. These tables grow with traffic, not with the catalogue — on a shared
     | host they are the first thing to eat the disk quota and slow the
     | dashboard's COUNT(DISTINCT …) queries.
     |
     | Only diagnostic/analytics tables belong here. Business records (orders,
     | order_status_history, customers) are never pruned automatically.
     |
     | 0 disables pruning for that table.
     */
    'days' => [
        // One row per pageview — by far the fastest-growing table.
        // 90 days still covers the dashboard's 30-day windows twice over.
        'visits' => (int) env('RETAIN_VISITS_DAYS', 90),

        // Delivery receipts. Useful for disputes, so kept longer.
        'sms_logs' => (int) env('RETAIN_SMS_LOGS_DAYS', 180),

        // Meta catalogue sync attempts — pure diagnostics.
        'meta_sync_logs' => (int) env('RETAIN_META_SYNC_LOGS_DAYS', 60),
        'meta_access_logs' => (int) env('RETAIN_META_ACCESS_LOGS_DAYS', 90),

        // Who changed which setting. Audit trail: kept a year.
        'config_audit_logs' => (int) env('RETAIN_CONFIG_AUDIT_LOGS_DAYS', 365),
    ],

    /*
     | Rows deleted per statement. Small batches keep each DELETE short so a
     | first run against a large table can't lock it or hit the host's timeout.
     */
    'chunk' => (int) env('RETAIN_CHUNK', 1000),

    /*
     | Size (MB) at which logs:prune mentions the pre-rotation laravel.log.
     | It is only ever reported, never removed, unless --archive-legacy is given.
     */
    'legacy_log_mb' => (float) env('RETAIN_LEGACY_LOG_MB', 20),
];
