<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * The visitor's language: English or Bangla. A cookie for everyone, the
 * customer record for members, and the chat assistant sets it the moment
 * someone writes in Bangla script — so the site can greet them in Bangla the
 * next time round, not only inside the chat.
 */
class Locale
{
    public const COOKIE = 'lang';

    public const SUPPORTED = ['en', 'bn'];

    public static function current(): string
    {
        $customer = auth('customer')->user();
        $lang = $customer?->locale ?: request()->cookie(self::COOKIE);

        return in_array($lang, self::SUPPORTED, true) ? $lang : 'en';
    }

    public static function isBangla(): bool
    {
        return self::current() === 'bn';
    }

    public static function remember(string $lang): void
    {
        if (! in_array($lang, self::SUPPORTED, true)) {
            return;
        }

        Cookie::queue(cookie(self::COOKIE, $lang, 60 * 24 * 365));
        request()->cookies->set(self::COOKIE, $lang);

        $customer = auth('customer')->user();
        if ($customer && $customer->locale !== $lang) {
            $customer->forceFill(['locale' => $lang])->saveQuietly();
        }
    }

    /** True when the text carries Bengali script. */
    public static function looksBangla(string $text): bool
    {
        return (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $text);
    }

    /**
     * Pick the string for the current language. Keys map to [en, bn] pairs;
     * a missing Bangla string falls back to English rather than a blank.
     */
    public static function t(string $en, string $bn): string
    {
        return self::isBangla() && $bn !== '' ? $bn : $en;
    }
}
