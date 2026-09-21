<?php

namespace App\Support;

/**
 * The shop's own social accounts — the icons in the footer and the phone
 * menu, and the Organization schema's sameAs.
 *
 * One list, so a platform is added in one place. Facebook and Instagram used
 * to be written out by hand five times over (two Blade blocks, two React
 * components, the schema), which is how adding TikTok and YouTube (owner,
 * 22 Sep 2026) turned into a five-file job. Each account is a theme key,
 * footer_<platform>, set under Appearance → Footer; the storefront icons live
 * in resources/js/Shared/Icons.jsx (SOCIAL_ICONS) for the React pages and in
 * `icon` below for the Blade ones.
 */
class SocialLinks
{
    /**
     * In display order. `base` turns a bare @handle into the profile address;
     * `hosts` are the addresses recognised when typed without https://.
     */
    public const PLATFORMS = [
        'facebook' => [
            'label' => 'Facebook',
            'field' => 'Facebook page',
            'placeholder' => 'https://facebook.com/yourpage',
            'base' => 'https://www.facebook.com/',
            'hosts' => ['facebook.com', 'fb.com', 'fb.me'],
            'icon' => 'M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987H7.898v-2.89h2.54V9.797c0-2.507 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'field' => 'Instagram',
            'placeholder' => 'https://instagram.com/yourpage',
            'base' => 'https://www.instagram.com/',
            'hosts' => ['instagram.com', 'instagr.am'],
            'icon' => 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'field' => 'TikTok',
            'placeholder' => 'https://www.tiktok.com/@yourshop',
            'base' => 'https://www.tiktok.com/@',
            'hosts' => ['tiktok.com', 'vm.tiktok.com'],
            'icon' => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'field' => 'YouTube channel',
            'placeholder' => 'https://www.youtube.com/@yourchannel',
            'base' => 'https://www.youtube.com/@',
            'hosts' => ['youtube.com', 'youtu.be'],
            'icon' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
        ],
    ];

    /**
     * An http(s) address with a real host — never javascript:, mailto: or a
     * path on this site. The admin form checks with this same pattern, so a
     * link it accepts is one the footer shows.
     */
    public const WEB_LINK = '~^https?://[^\s/?#]+\.[^\s/?#]+~iu';

    /** The theme key an account is stored under. */
    public static function key(string $platform): string
    {
        return 'footer_'.$platform;
    }

    /**
     * The accounts that are filled in, in display order. A stored value that
     * is not a web address is left out rather than linked.
     *
     * @return list<array{platform: string, label: string, url: string}>
     */
    public static function present(): array
    {
        $links = [];

        foreach (self::PLATFORMS as $platform => $meta) {
            $url = self::normalise(theme(self::key($platform)), $platform);

            if ($url !== null && self::isWebLink($url)) {
                $links[] = ['platform' => $platform, 'label' => $meta['label'], 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * What the owner typed, as an address. A full link is kept; one typed
     * without https:// gains it (otherwise the footer linked to a page of that
     * name on the shop's own site); a bare handle — "@noychoy", or just
     * "noychoy" — becomes the platform's profile address. Anything else comes
     * back as typed, for isWebLink() to refuse.
     */
    public static function normalise(?string $value, string $platform): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value)) {
            return $value;
        }

        if (str_starts_with($value, '//')) {
            return 'https:'.$value;
        }

        // "tiktok.com/@shop", "www.youtube.com/@shop", "m.facebook.com/page",
        // or any other address with a path.
        $hosts = implode('|', array_map(fn ($h) => preg_quote($h, '~'), self::PLATFORMS[$platform]['hosts'] ?? []));
        if (($hosts !== '' && preg_match('~^([a-z0-9-]+\.)?('.$hosts.')(/|$)~i', $value))
            || preg_match('~^www\.~i', $value)
            || preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+/~i', $value)) {
            return 'https://'.$value;
        }

        // A handle. Instagram and TikTok names may carry dots and underscores.
        if (preg_match('~^@?([A-Za-z0-9._-]+)$~', $value, $m)) {
            return self::PLATFORMS[$platform]['base'].$m[1];
        }

        return $value;
    }

    public static function isWebLink(string $url): bool
    {
        return (bool) preg_match(self::WEB_LINK, $url);
    }
}
