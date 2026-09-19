<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * The Terms & Conditions page, rewritten for the shop it belongs to (owner's
 * ask, 19 Sep 2026: "go through the terms and conditions and amend it").
 *
 * The stored page was Shopify's stock Terms of Service pasted in whole: it
 * welcomed shoppers to "My Store", said the shop is "powered by Shopify" (it
 * is not), kept a "[NOTE TO MERCHANT]" line, "[LINK]" and "[INSERT BUSINESS
 * ADDRESS]" placeholders, billed credit cards on a cash-on-delivery shop, sent
 * order confirmations by e-mail, passed the risk to the buyer at the courier's
 * door and put disputes before "federal and state courts".
 *
 * The new text says what the shop actually does — cash on delivery across
 * Bangladesh, orders confirmed by phone, the chat assistant that can place an
 * order only after the shopper confirms its summary — and, new today, how
 * prices and offers work: a live offer lists a piece at the discounted price
 * with its regular price crossed out, and the price goes back when the offer
 * ends. Returns defer to the Refund & Return Policy page rather than repeat it,
 * so the two cannot drift apart.
 *
 * Only the untouched Shopify template is replaced: a stored page that no
 * longer mentions Shopify or its placeholders is the owner's own writing and
 * is left alone. The old text is kept in storage/app/private/backups, and the
 * page stays editable in Admin → Pages like every other.
 */
return new class extends Migration
{
    private const BODY = <<<'HTML'
<p><em>Last updated: 19 September 2026</em></p>
<p>These terms apply when you visit noychoy.com or order from NoyChoy — on this website, through our chat assistant, or by message or phone. "We", "us" and "our" mean NoyChoy. By placing an order you agree to them, so please read them before you buy.</p>

<h3>1. Using this website</h3>
<p>Please give us true and complete details when you order — above all your name, phone number and delivery address, because that is how the courier finds you. If you are under 18, please order with a parent or guardian.</p>
<p>You don't need an account to shop. If you create one, keep your password to yourself: you are responsible for what is done with your account.</p>

<h3>2. Our products</h3>
<p>We describe and photograph every piece as accurately as we can. Colours can look slightly different on different screens, and handcrafted details can vary a little from piece to piece. The materials, plating and stones of each piece are as described on its product page.</p>
<p>Stock is limited. We may stop selling a piece at any time, and we may limit how many of one piece a customer can order.</p>

<h3>3. Prices and offers</h3>
<ul>
<li>All prices are in Bangladeshi Taka (৳).</li>
<li>The price shown on a product is the price it sells for today. While an offer covers a product, we show the discounted price with the product's regular price crossed out beside it. The regular price is what the product sells for on our website when no offer is running, and it returns when the offer ends.</li>
<li>Offers run for a limited time. We may change or end an offer at any time without notice. An order already placed keeps the price and discounts it was placed with.</li>
<li>Some offers have conditions — a minimum order value, a minimum number of pieces, or being signed in as a member. These are applied in the cart and at checkout once the conditions are met, and each discount is listed there.</li>
<li>Only one coupon can be used per order. Some coupons cannot be combined with other offers; when that happens, the order gets whichever saving is bigger. A coupon given to a phone number works only with that number. Coupons, offers and rewards have no cash value.</li>
<li>Delivery charges depend on where the parcel is going and are shown at checkout, before you place your order. Free delivery applies when an offer says so and its conditions are met.</li>
<li>The total shown when you place your order — the pieces, any discounts and the delivery charge — is what you pay.</li>
<li>If a price or offer was shown wrongly because of a clear mistake, we will contact you before sending the order. You can then confirm it at the correct price or cancel it at no cost.</li>
</ul>

<h3>4. Orders</h3>
<p>Placing an order is an offer to buy. We may call or message you to confirm it before we send it. We may decline or cancel an order — for example when a piece is out of stock, when a price was shown in error, when we cannot reach you to confirm, or when we have good reason to believe the order is not genuine. If we cancel, you pay nothing.</p>
<p>Our chat assistant can help you choose a piece and place an order for you. It places an order only after showing you the full summary — the pieces, any discounts, the delivery charge and the total — and after you confirm it. An order placed this way is the same as one placed on the website.</p>
<p>If you want to change or cancel an order, please contact us as soon as you can, before it is handed to the courier.</p>

<h3>5. Payment</h3>
<p>We take cash on delivery: you pay the courier in cash when your parcel arrives, so please keep the amount ready. We do not take card payments on this website.</p>

<h3>6. Delivery</h3>
<p>We deliver across Bangladesh through courier partners. Delivery times are estimates and can be affected by the courier, weather, holidays, strikes and other events outside our control. The courier will contact you before delivery.</p>
<p>Please check your parcel when it arrives. If it is damaged or opened, you can refuse it, or tell us within the time given in our Refund &amp; Return Policy. A parcel is your responsibility from the moment you receive it. If deliveries to a number are refused without a good reason, we may ask for confirmation, or decline further orders to that number.</p>

<h3>7. Returns and refunds</h3>
<p>Returns, exchanges and refunds are covered by our <a href="/refund-policy">Refund &amp; Return Policy</a>, which forms part of these terms.</p>

<h3>8. Member prices, rewards and points</h3>
<p>From time to time we may offer member prices, rewards for buying more pieces, loyalty points or referral rewards. Their current rules are shown in the cart, at checkout or in your account. They cannot be exchanged for cash or transferred, and we may change or end them; anything already applied to a placed order stays.</p>

<h3>9. Reviews and what you send us</h3>
<p>Reviews, photos and messages you send us must be honest and your own, and must not be abusive or break anyone's rights. By posting a review or a photo, you let us show it on our website and social media. We may hide or remove reviews that break these rules.</p>

<h3>10. Our content</h3>
<p>The text, photos, videos, designs and logo on this website belong to NoyChoy or to those who licensed them to us. Please don't copy them for commercial use without our written permission.</p>

<h3>11. Fair use of the website</h3>
<p>Please don't misuse the website: no fake or prank orders, no attempts to break into or overload it, no automated ordering, and no pretending to be someone else.</p>

<h3>12. Other services</h3>
<p>Our website links to services run by others, such as Facebook, Messenger, WhatsApp and our courier partners' tracking pages. Their own terms apply when you use them.</p>

<h3>13. Privacy</h3>
<p>How we collect and use your information is explained in our <a href="/privacy-policy">Privacy Policy</a>.</p>

<h3>14. Our responsibility</h3>
<p>Nothing in these terms limits your rights under the Consumer Rights Protection Act, 2009 or any other law of Bangladesh that cannot be set aside by agreement. Beyond those rights, our responsibility for an order is limited to the amount you paid for it, and we are not responsible for losses we could not reasonably foresee or for delays caused by events outside our control.</p>

<h3>15. Governing law</h3>
<p>These terms are governed by the laws of Bangladesh, and the courts of Bangladesh have jurisdiction over any dispute about them. If we cannot agree, please contact us first — most problems are solved with a phone call.</p>

<h3>16. Changes to these terms</h3>
<p>We may update these terms from time to time; the date at the top shows the latest version. An order is governed by the terms in force when it was placed. If any part of these terms is found unenforceable, the rest still applies.</p>

<h3>17. Contact us</h3>
<p>Questions about these terms or an order? E-mail <a href="mailto:support@noychoy.com">support@noychoy.com</a>, call or WhatsApp <a href="https://wa.me/8801634347164">+880 1634-347164</a>, or message us on <a href="https://m.me/noychoylove">Messenger</a>. You can also reach us through our <a href="/contact">contact page</a>.</p>
HTML;

    public function up(): void
    {
        $pages = Setting::get('pages');

        if (! is_array($pages)) {
            return;   // nothing stored: the page shows config/pages.php, which never carried the template
        }

        $old = (string) ($pages['terms']['body'] ?? '');

        if ($old !== '' && ! $this->isShopifyTemplate($old)) {
            return;   // the owner's own writing
        }

        if ($old !== '') {
            Storage::disk('local')->put('backups/terms-before-2026-09-19.html', $old);
        }

        $pages['terms'] = array_merge((array) ($pages['terms'] ?? []), [
            'title' => 'Terms & Conditions',
            'body' => self::BODY,
        ]);

        Setting::put('pages', $pages);
    }

    /** The stock text announces itself: the platform's name, "My Store", or its placeholders. */
    private function isShopifyTemplate(string $body): bool
    {
        return str_contains($body, 'Shopify')
            || str_contains($body, 'My Store')
            || str_contains($body, '[INSERT');
    }

    public function down(): void
    {
        // Put the old text back only where this migration took it away.
        $pages = Setting::get('pages');

        if (! is_array($pages) || ($pages['terms']['body'] ?? null) !== self::BODY
            || ! Storage::disk('local')->exists('backups/terms-before-2026-09-19.html')) {
            return;
        }

        $pages['terms']['body'] = Storage::disk('local')->get('backups/terms-before-2026-09-19.html');
        Setting::put('pages', $pages);
    }
};
