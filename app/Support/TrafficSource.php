<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Works out where a visitor came from: a channel ("Facebook", "Google search",
 * "Direct"), the campaign and ad behind it, and the referring host.
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
        'instagram_ads' => ['label' => 'Instagram Ads', 'color' => 'pink'],
        'audience_network' => ['label' => 'Meta Audience Network', 'color' => 'violet'],
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

    /**
     * Exact utm_source values that are a platform under a short name.
     *
     * Matched whole, not as substrings: "fb" appearing inside some other word
     * means nothing, and a two-letter substring test would misfile half the web
     * as Facebook. These are the values Meta's own `{{site_source_name}}` macro
     * emits — the reason a tagged ad click used to land in "Other website".
     */
    protected const SOURCE_ALIASES = [
        'fb' => 'facebook',
        'facebook' => 'facebook',
        'meta' => 'facebook',
        'ig' => 'instagram',
        'insta' => 'instagram',
        'instagram' => 'instagram',
        'an' => 'audience_network',
        'audience_network' => 'audience_network',
        'msg' => 'messenger',
        'messenger' => 'messenger',
        'wa' => 'whatsapp',
        'whatsapp' => 'whatsapp',
        'tt' => 'tiktok',
        'tiktok' => 'tiktok',
        'yt' => 'youtube',
        'youtube' => 'youtube',
        'google' => 'google',
        'adwords' => 'google_ads',
        'bing' => 'bing',
        'newsletter' => 'email',
        'email' => 'email',
        'sms' => 'sms',
        'push' => 'push',
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

    /** utm_medium values that mean somebody paid for the click. */
    protected const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paid_social', 'paidsocial', 'ads', 'ad', 'display', 'cpm'];

    /** Query parameters carrying a platform's own ad id, best first. */
    protected const AD_ID_PARAMS = ['ad_id', 'adid', 'utm_ad_id', 'adset_id', 'campaign_id', 'utm_id'];

    /** Organic → paid counterpart, applied once we know money changed hands. */
    protected const PAID_VARIANT = [
        'facebook' => 'facebook_ads',
        'instagram' => 'instagram_ads',
        'google' => 'google_ads',
    ];

    /**
     * Resolve a request into the full attribution set.
     *
     * @return array{channel:string, campaign:?string, referrer:?string, medium:?string, content:?string, ad_id:?string}
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
            'medium' => self::str($request->query('utm_medium'), 40),
            // Only a *separate* utm_content is the ad name. When utm_campaign is
            // missing, utm_content is standing in as the campaign above, and
            // repeating it here would invent an ad that doesn't exist.
            'content' => filled($request->query('utm_campaign'))
                ? self::str($request->query('utm_content'))
                : null,
            'ad_id' => self::adId($request),
        ];
    }

    protected static function resolve(Request $request, ?string $referrerHost): string
    {
        $source = strtolower((string) self::str($request->query('utm_source')));
        $medium = strtolower((string) self::str($request->query('utm_medium')));
        $paid = self::looksPaid($request, $medium);

        // 1. An explicit utm_source wins — the store set it themselves.
        if ($source !== '') {
            // Whole-value aliases first ("fb", "ig", "an"), then the substring
            // pass that catches "Facebook_Mobile_Feed" and friends.
            if ($channel = self::SOURCE_ALIASES[$source] ?? null) {
                return $paid ? (self::PAID_VARIANT[$channel] ?? $channel) : $channel;
            }

            foreach (self::HOSTS as $needle => $channel) {
                if (str_contains($source, rtrim($needle, '.'))) {
                    return $paid ? (self::PAID_VARIANT[$channel] ?? $channel) : $channel;
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
                // so only call it Ads when something else says it was paid.
                return $paid ? (self::PAID_VARIANT[$channel] ?? $channel) : $channel;
            }
        }

        // 3. Fall back to the referring host.
        if ($referrerHost) {
            foreach (self::HOSTS as $needle => $channel) {
                if (str_contains($referrerHost, $needle)) {
                    return $paid ? (self::PAID_VARIANT[$channel] ?? $channel) : $channel;
                }
            }

            return 'referral';
        }

        return 'direct';
    }

    /**
     * Did somebody pay for this click?
     *
     * utm_medium is the honest answer when it's set. It very often isn't — a
     * link built with Meta's macros can carry only `utm_source={{site_source_name}}`
     * and `utm_campaign={{campaign.id}}` — so an ad id in the URL counts too:
     * organic posts and shares never carry one.
     */
    protected static function looksPaid(Request $request, string $medium): bool
    {
        if (in_array($medium, self::PAID_MEDIUMS, true)) {
            return true;
        }

        foreach (self::AD_ID_PARAMS as $param) {
            if (filled($request->query($param))) {
                return true;
            }
        }

        // Meta's {{campaign.id}} macro resolves to a long numeric id. A human
        // naming a campaign does not write seventeen digits and nothing else.
        $campaign = (string) self::str($request->query('utm_campaign'));

        return $campaign !== '' && ctype_digit($campaign) && strlen($campaign) >= 12;
    }

    /** The platform's own ad id, if the link carries one under any known name. */
    protected static function adId(Request $request): ?string
    {
        foreach (self::AD_ID_PARAMS as $param) {
            if ($value = self::str($request->query($param), 40)) {
                return $value;
            }
        }

        return null;
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

    /**
     * The channel a bare referrer host belongs to, with no query string to go
     * on. Used to re-file rows recorded before a mis-tagged link was understood
     * (see `visits:reclassify`), never on the request path.
     */
    public static function fromReferrerHost(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return null;
        }

        foreach (self::HOSTS as $needle => $channel) {
            if (str_contains($host, $needle)) {
                return $channel;
            }
        }

        return null;
    }

    protected static function str($value, int $limit = 80): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, $limit);
    }
}
