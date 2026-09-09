<?php

namespace App\Support;

use App\Models\AbandonedCart;
use App\Models\Setting;
use Illuminate\Support\Facades\URL;

/**
 * Everything needed to chase one abandoned cart: the link that puts the
 * shopper's basket back, and the opening line that carries it.
 *
 * The link is the whole point. A bare "come back and shop" message lands a
 * cash-on-delivery shopper on an empty cart and wastes the message, so every
 * channel here — WhatsApp, SMS, the copy button — sends the same signed
 * `cart.restore` URL that the automated reminder already used.
 */
class AbandonedCartOutreach
{
    /** Kept in one place so the WhatsApp button and the settings hint agree. */
    public const WHATSAPP_DEFAULT =
        'Hello {name}, this is {store}. You left {items} in your cart ({total}). '
        .'Tap here to bring it back and finish your order: {link}';

    /** Placeholders the owner may use, for the help text under the field. */
    public const PLACEHOLDERS = ['{name}', '{store}', '{items}', '{qty}', '{total}', '{link}'];

    /**
     * The signed link that rebuilds this cart.
     *
     * Deliberately un-expiring, matching the automated reminder: a shopper
     * who opens a two-day-old WhatsApp message should still land on her cart
     * rather than a 403.
     */
    public static function restoreLink(AbandonedCart $cart): string
    {
        return URL::signedRoute('cart.restore', ['cart' => $cart->id]);
    }

    public static function whatsappTemplate(): string
    {
        $stored = trim((string) Setting::get('whatsapp_abandoned_template'));

        return $stored !== '' ? $stored : self::WHATSAPP_DEFAULT;
    }

    /** The prefilled first message, with the restore link substituted in. */
    public static function whatsappMessage(AbandonedCart $cart): string
    {
        $template = self::whatsappTemplate();

        // An owner who edits the wording should not be able to drop the link
        // by accident — that is the only part of the message that recovers
        // the sale. Append it rather than send a message that goes nowhere.
        if (! str_contains($template, '{link}')) {
            $template = rtrim($template).' {link}';
        }

        return strtr($template, [
            '{name}' => $cart->firstName(),
            '{store}' => store_name(),
            '{items}' => $cart->itemSummary(),
            '{qty}' => (string) $cart->item_count,
            '{total}' => money($cart->subtotal),
            '{link}' => self::restoreLink($cart),
        ]);
    }

    /** wa.me link with the message prefilled, or null when there is no number. */
    public static function whatsappLink(AbandonedCart $cart): ?string
    {
        return wa_link($cart->phone, self::whatsappMessage($cart));
    }
}
