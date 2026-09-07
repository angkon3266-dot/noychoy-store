<?php

namespace App\Support;

/**
 * The reward ladder's "next rung" as one short sentence for messages sent
 * away from the cart (abandoned-cart SMS and push), computed from a unit
 * count rather than a live cart — a queued job has no session.
 */
class LadderLine
{
    /**
     * " Add 1 more piece for free delivery." — with its own leading space so
     * the template closes cleanly when the ladder is off, matching the
     * {offer} convention in config/sms.php. Empty when nothing is next.
     */
    public static function forUnits(int $units, string $lang = 'en'): string
    {
        $ladder = app(GiftLadder::class);
        if (! $ladder->enabled()) {
            return '';
        }

        foreach ($ladder->tiers() as $tier) {
            if ($units < $tier['threshold']) {
                $more = $tier['threshold'] - $units;
                $reward = $tier['type'] === 'free_gift' ? 'a free gift' : lcfirst($tier['label']);

                return $lang === 'bn'
                    ? ' আর '.$more.'টি যোগ করলেই '.$reward.'।'
                    : ' Add '.$more.' more '.($more === 1 ? 'piece' : 'pieces').' for '.$reward.'.';
            }
        }

        return '';
    }
}
