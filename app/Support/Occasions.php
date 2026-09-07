<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Customer;
use App\Models\Setting;

/**
 * Settings and lookups for the birthday / anniversary automations
 * (Admin → Offers). Defaults live in config/loyalty.php under `occasions`.
 */
class Occasions
{
    public static function enabled(): bool
    {
        return (bool) Setting::get('occasion_enabled', config('loyalty.occasions.enabled', true));
    }

    public static function smsEnabled(): bool
    {
        return (bool) Setting::get('occasion_sms', config('loyalty.occasions.sms', true));
    }

    public static function reminderDays(): int
    {
        return max(1, (int) Setting::get('occasion_reminder_days', config('loyalty.occasions.reminder_days', 10)));
    }

    public static function offerPercent(): float
    {
        return max(0, min(90, (float) Setting::get('occasion_offer_percent', config('loyalty.occasions.offer_percent', 0))));
    }

    public static function offerDays(): int
    {
        return max(1, (int) Setting::get('occasion_offer_days', config('loyalty.occasions.offer_days', 7)));
    }

    public static function perRun(): int
    {
        return max(1, (int) Setting::get('occasion_per_run', config('loyalty.occasions.per_run', 200)));
    }

    public static function label(string $occasion): string
    {
        return Customer::OCCASIONS[$occasion] ?? ucfirst($occasion);
    }

    /** The Bangla word for the day, for the greeting surfaces. */
    public static function labelBn(string $occasion): string
    {
        return ['birthday' => 'জন্মদিন', 'anniversary' => 'বিবাহবার্ষিকী'][$occasion] ?? $occasion;
    }

    /**
     * Where to send someone for the occasion: the matching collection when it
     * exists (the owner's own occasion collections), else the shop.
     */
    public static function collectionUrl(string $occasion): string
    {
        $slugs = ['birthday' => ['birthday-gift', 'birthday'], 'anniversary' => ['anniversary']][$occasion] ?? [];

        foreach ($slugs as $slug) {
            if ($c = Collection::active()->where('slug', $slug)->first()) {
                return $c->url();
            }
        }

        return route('shop');
    }
}
