<?php

namespace App\Support;

/**
 * What a visitor is browsing on, read from the user agent (owner, 6 Oct 2026:
 * "add option to see in which device the site is being browsed").
 *
 * Hand-written rather than a parsing library: three coarse answers are all the
 * dashboard needs, and the one this shop cares most about — which in-app
 * browser an ad click opened in (Facebook, Instagram, Messenger) — is exactly
 * what general-purpose parsers lump together as "Chrome WebView".
 *
 * An iPad on iPadOS 13+ reports itself as a Mac and is counted as a desktop;
 * nothing server-side can tell them apart.
 */
class DeviceDetector
{
    public const DEVICES = ['mobile' => 'Phone', 'tablet' => 'Tablet', 'desktop' => 'Computer'];

    /** @return array{device:?string, os:?string, browser:?string} */
    public static function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return ['device' => null, 'os' => null, 'browser' => null];
        }

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            stripos($ua, 'Android') !== false => 'Android',
            stripos($ua, 'Windows') !== false => 'Windows',
            stripos($ua, 'CrOS') !== false => 'ChromeOS',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
            stripos($ua, 'Linux') !== false => 'Linux',
            default => 'Other',
        };

        $device = match (true) {
            (bool) preg_match('/iPad|Tablet/i', $ua) => 'tablet',
            // Android phones say "Mobile"; Android tablets leave it out.
            $os === 'Android' && stripos($ua, 'Mobile') === false => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android/i', $ua) => 'mobile',
            default => 'desktop',
        };

        // In-app browsers first: they also carry "Chrome" or "Safari".
        $browser = match (true) {
            stripos($ua, 'Instagram') !== false => 'Instagram app',
            (bool) preg_match('/MessengerForiOS|FBAN\/Messenger|Orca-Android/i', $ua) => 'Messenger app',
            (bool) preg_match('/FBAN|FBAV|FB_IAB|FBIOS|FB4A/i', $ua) => 'Facebook app',
            (bool) preg_match('/musical_ly|BytedanceWebview|TikTok/i', $ua) => 'TikTok app',
            stripos($ua, 'SamsungBrowser') !== false => 'Samsung Internet',
            (bool) preg_match('/OPR\/|Opera|OPiOS/i', $ua) => 'Opera',
            (bool) preg_match('/UCBrowser|UCWEB/i', $ua) => 'UC Browser',
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/Firefox|FxiOS/i', $ua) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/', $ua) => 'Chrome',
            stripos($ua, 'Safari') !== false => 'Safari',
            default => 'Other',
        };

        return ['device' => $device, 'os' => $os, 'browser' => $browser];
    }
}
