<?php

namespace App\Support;

/**
 * The store's WhatsApp catalogue link (Nov 2026).
 *
 * The products themselves reach WhatsApp through the Meta catalogue the
 * hourly CSV feed already fills (/feed/meta.csv): the owner connects that
 * catalogue to the WhatsApp Business account once, in WhatsApp Manager or
 * the WhatsApp Business app. Nothing here talks to Meta.
 *
 * What this decides is whether the storefront offers "Browse our catalogue on
 * WhatsApp". A wa.me/c/<number> link opens the business's catalogue, but
 * WhatsApp answers "Invalid catalog link" while no catalogue is connected or
 * it is switched off, so the link stays hidden until the owner ticks
 * Appearance → "My WhatsApp catalogue is connected".
 */
class WhatsAppCatalog
{
    public static function url(): ?string
    {
        if (! theme('whatsapp_catalog')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) theme('whatsapp_number'));

        return strlen($digits) >= 8 ? 'https://wa.me/c/'.$digits : null;
    }
}
