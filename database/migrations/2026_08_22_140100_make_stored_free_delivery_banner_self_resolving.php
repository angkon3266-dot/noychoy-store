<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The announcement bar advertised "Free delivery on orders over ৳3000" while
 * the checkout charged full delivery, because the message was arbitrary free
 * text with no connection to the threshold.
 *
 * This rewrites the amount inside a stored free-delivery message to the
 * {free_delivery} placeholder, which prints the live threshold — and hides the
 * whole message while the promise is switched off. It deliberately does NOT
 * decide whether free delivery should be on: that is a margin call for the
 * store owner. It only makes the banner tell the truth about whatever they
 * decide.
 *
 * Conservative on purpose — it only touches a message that both mentions free
 * delivery/shipping and contains exactly one money amount, so a message like
 * "Free delivery over ৳3000, or ৳500 off over ৳10000" is left alone rather
 * than being mangled.
 */
return new class extends Migration
{
    public function up(): void
    {
        $theme = Setting::get('theme');

        if (! is_array($theme) || ! isset($theme['announcement_messages']) || ! is_array($theme['announcement_messages'])) {
            return;
        }

        $changed = false;
        foreach ($theme['announcement_messages'] as $i => $message) {
            if (! is_string($message)) {
                continue;
            }
            if (str_contains($message, '{free_delivery}')) {
                continue; // already self-resolving
            }
            if (! preg_match('/free\s+(delivery|shipping)/i', $message)) {
                continue;
            }

            // One money amount only: "৳3000", "Tk 3,000", "3000 taka", "BDT 3000".
            $amount = '/(?:৳|\bTk\.?|\bBDT)\s*[\d,]+(?:\.\d+)?|[\d,]{3,}(?:\.\d+)?\s*(?:taka|tk)\b/iu';
            if (preg_match_all($amount, $message, $m) !== 1) {
                continue;
            }

            $theme['announcement_messages'][$i] = str_replace($m[0][0], '{free_delivery}', $message);
            $changed = true;
        }

        if ($changed) {
            Setting::put('theme', $theme);
        }
    }

    public function down(): void
    {
        // Not reversible: the literal amount is exactly what made the banner
        // able to contradict the checkout. The owner can retype one if they
        // really want the two able to disagree again.
    }
};
