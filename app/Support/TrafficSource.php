<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Works out where a visitor came from: a channel ("Facebook", "Google search",
 * "Direct"), the campaign behind it, and the referring host.
 *
 * Signals, strongest first:
 *   1. utm_source / utm_medium — set deliberately, so trusted above all
 *   2. ad click ids (fbclid, gclid, ttclid…) — present even when the referrer
 *      is stripped, which is the usual case on mobile in-app browsers
 *   3. the referrer host
 *   4. nothing → direct (typed the URL, a bookmark, or an app with no referrer)
 */
class TrafficSource
{
    /** Channel keys with the label and badge colour the admin sees. */
    public const CHANNELS = [
        'direct' => ['label' => 'Direct', 'color' => 'ink'],
        'facebook' => ['label' => 'Facebook', 'color' => 'blue'],
        'facebook_ads' => ['label' => 'Facebook Ads', 'color' => 'blue'],
        'instagram' => ['label' => 'Instagram', 'color' => 'pink'],
        'google' => ['label' => 'Google search', 'color' => 'green'],
        'google_ads' => ['label' => 'Google Ads', 'color' => 'green'],
        'tiktok' => ['label' => 'TikTok', 'color' => 'ink'],
        'youtube' => ['label' => 'YouTube', 'color' => 'red'],
        'whatsapp' => ['label' => 'WhatsApp', 'color' => 'green'],
        'messenger' => ['label' => 'Messenger', 'color' => 'blue'],
        'bing' => ['label' => 'Bing search', 'color' => 'green'],
        'email' => ['label' => 'Email', 'color' => 'amber'],
        'sms' => ['label' => 'SMS', 'color' => 'amber'],
        'push' => ['label' => 'Push notification', 'color' => 'violet'],
        'referral' => ['label' => 'Other website', 'color' => 'amber'],
    ];

    /** Referrer host fragment → channel. Checked as substrings, longest first. */
    protected const HOSTS = [
        'facebook' => 'facebook',
        'fb.com' => 'facebook',
        'fb.me' => 'facebook',
        'messenger.com' => 'messenger',
        'instagram' => 'instagram',
        'tiktok' => 'tiktok',
        'youtube' => 'youtube',
        'youtu.be' => 'youtube',
        'whatsapp' => 'whatsapp',
        'google' => 'google',
        'bing.' => 'bing',
        'duckduckgo' => 'bing',
        'yahoo' => 'bing',
        'mail.' => 'email',
        'outlook' => 'email',
    ];

    /** Ad/click id → channel, for when the referrer is missing. */
    protected const CLICK_IDS = [
        'fbclid' => 'facebook',
        'gclid' => 'google_ads',
        'gbraid' => 'google_ads',
        'wbraid' => 'google_ads',
        'ttclid' => 'tiktok',
        'msclkid' => 'bing',
    ];

    /**
     * Resolve a request into ['channel', 'campaign', 'referrer'].
     *
     * @return array{channel:string, campaign:?string, referrer:?string}
     */
    public static function fromRequest(Request $request): array
    {
        $referrerHost = ($ref = $request->headers->get('referer'))
            ? strtolower((string) parse_url($ref, PHP_URL_HOST))
            : null;

        // Our own pages aren't a source — that's internal navigation. Guard the
        // empty case: str_contains($x, '') is always true in PHP 8, so an
        // unparseable APP_URL would have made every referrer look internal and
        // reported all traffic as Direct.
        $ownHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($referrerHost && $ownHost !== '' && str_contains($referrerHost, $ownHost)) {
            $referrerHost = null;
        }

        $campaign = self::str($request->query('utm_campaign'))
            ?: self::str($request->query('utm_content'));

        return [
            'channel' => self::resolve($request, $referrerHost),
            'campaign' => $campaign,
            'referrer' => $referrerHost ? substr($referrerHost, 0, 120) : null,
        ];
    }

    protected static function resolve(Request $request, ?string $referrerHost): string
    {
        $source = strtolower((string) self::str($request->query('utm_source')));
        $medium = strtolower((string) self::str($request->query('utm_medium')));

        // 1. An explicit utm_source wins — the store set it themselves.
        if ($source !== '') {
            $paid = in_array($medium, ['cpc', 'ppc', 'paid', 'paid_social', 'ads'], true);

            foreach (self::HOSTS as $needle => $channel) {
                if (str_contains($source, rtrim($needle, '.'))) {
                    return match (true) {
                        $paid && $channel === 'facebook' => 'facebook_ads',
                        $paid && $channel === 'google' => 'google_ads',
                        default => $channel,
                    };
                }
            }

            return match (true) {
                in_array($medium, ['email', 'newsletter'], true) => 'email',
                $medium === 'sms' => 'sms',
                $medium === 'push' => 'push',
                default => 'referral',
            };
        }

        // 2. A click id survives when the referrer doesn't.
        foreach (self::CLICK_IDS as $param => $channel) {
            if (filled($request->query($param))) {
                // fbclid on a paid click means an ad; organic posts carry it too,
                // so only call it Ads when the medium says so.
                return $channel === 'facebook' && $medium !== '' ? 'facebook_ads' : $channel;
            }
        }

        // 3. Fall back to the referring host.
        if ($referrerHost) {
            foreach (self::HOSTS as $needle => $channel) {
                if (str_contains($referrerHost, $needle)) {
                    return $channel;
                }
            }

            return 'referral';
        }

        return 'direct';
    }

    public static function label(?string $channel): string
    {
        return self::CHANNELS[$channel]['label'] ?? 'Direct';
    }

    public static function color(?string $channel): string
    {
        return self::CHANNELS[$channel]['color'] ?? 'ink';
    }

    /** Tailwind badge classes for a channel (kept here so views stay dumb). */
    public static function badgeClass(?string $channel): string
    {
        return match (self::color($channel)) {
            'blue' => 'bg-blue-100 text-blue-700',
            'pink' => 'bg-pink-100 text-pink-700',
            'green' => 'bg-green-100 text-green-700',
            'red' => 'bg-red-100 text-red-700',
            'amber' => 'bg-amber-100 text-amber-700',
            'violet' => 'bg-violet-100 text-violet-700',
            default => 'bg-ink-100 text-ink-600',
        };
    }

    protected static function str($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 80);
    }
}
