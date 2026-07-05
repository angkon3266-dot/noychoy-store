<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaSettings;

/**
 * Marketing Center — the hub for every sales/marketing channel. Meta is fully
 * functional; the rest are registered as "coming soon" placeholders so the
 * platform advertises the roadmap without dead links.
 */
class MarketingController extends Controller
{
    /** Channel registry. `route` set = functional; null = coming soon. */
    public const CHANNELS = [
        'meta' => ['name' => 'Meta (Facebook & Instagram)', 'desc' => 'Sync products to your Commerce Catalog for Shops, Advantage+ and dynamic ads.', 'route' => 'admin.meta.index', 'status' => 'active'],
        'google-merchant' => ['name' => 'Google Merchant Center', 'desc' => 'Product feed for Google Shopping & free listings.', 'route' => null, 'status' => 'soon'],
        'google-analytics' => ['name' => 'Google Analytics 4', 'desc' => 'Traffic and conversion analytics.', 'route' => null, 'status' => 'soon'],
        'google-tag-manager' => ['name' => 'Google Tag Manager', 'desc' => 'Manage tags without code changes.', 'route' => null, 'status' => 'soon'],
        'google-ads' => ['name' => 'Google Ads', 'desc' => 'Conversion tracking & remarketing.', 'route' => null, 'status' => 'soon'],
        'tiktok' => ['name' => 'TikTok', 'desc' => 'TikTok catalog & Events API.', 'route' => null, 'status' => 'soon'],
        'pinterest' => ['name' => 'Pinterest', 'desc' => 'Catalog & conversion tracking.', 'route' => null, 'status' => 'soon'],
        'snapchat' => ['name' => 'Snapchat', 'desc' => 'Catalog & Snap Pixel.', 'route' => null, 'status' => 'soon'],
        'pixel' => ['name' => 'Pixel', 'desc' => 'Browser-side event tracking.', 'route' => null, 'status' => 'soon'],
        'conversions-api' => ['name' => 'Conversions API', 'desc' => 'Server-side event tracking.', 'route' => null, 'status' => 'soon'],
    ];

    public function __construct(private readonly MetaSettings $settings) {}

    public function index()
    {
        // Live status for the Meta card.
        $metaStatus = match (true) {
            $this->settings->isEnabled() && $this->settings->isConfigured() => 'Connected',
            $this->settings->isConfigured() => 'Configured',
            default => 'Not connected',
        };

        return view('admin.marketing.index', [
            'channels' => self::CHANNELS,
            'metaStatus' => $metaStatus,
        ]);
    }

    /** Coming-soon detail page for a non-Meta channel. */
    public function channel(string $channel)
    {
        abort_unless(isset(self::CHANNELS[$channel]), 404);

        $config = self::CHANNELS[$channel];

        // Functional channels redirect to their own module.
        if ($config['route']) {
            return redirect()->route($config['route']);
        }

        return view('admin.marketing.coming-soon', [
            'channel' => $config,
            'key' => $channel,
        ]);
    }
}
