<?php

namespace App\Support;

/**
 * Ready-made landing page layouts.
 *
 * Each one is just a pre-arranged list of the blocks the builder already
 * renders (see resources/views/components/home-block.blade.php) with
 * placeholder copy filled in. Picking a template is a one-time starting point:
 * the blocks are copied into the page and from then on it is an ordinary page
 * the admin edits block by block. There is no ongoing link back to the template.
 *
 * Copy is written for a Bangladeshi COD storefront — cash on delivery, courier
 * nationwide, order-by-phone reassurance — because that is what these pages are
 * actually used to sell.
 */
class LandingTemplates
{
    /**
     * @return array<string, array{name:string, tagline:string, best_for:string, icon:string, blocks:array}>
     */
    public static function all(): array
    {
        return [
            'product_sales' => [
                'name' => 'Single product sales page',
                'tagline' => 'Hero → benefits → buy box → countdown → reviews → FAQ, with a sticky order bar.',
                'best_for' => 'Facebook ad traffic for one hero product. The highest-converting layout for cold visitors.',
                'icon' => '🎯',
                'blocks' => self::productSales(),
            ],
            'flash_sale' => [
                'name' => 'Flash sale / campaign',
                'tagline' => 'Countdown first, then a discounted line-up and trust badges.',
                'best_for' => 'Eid, Puja, Black Friday — any dated push where urgency does the selling.',
                'icon' => '⚡',
                'blocks' => self::flashSale(),
            ],
            'lead_capture' => [
                'name' => 'Lead capture',
                'tagline' => 'Short page: one offer, three reasons to believe it, one action.',
                'best_for' => 'Pre-orders and "we\'ll call you back" campaigns where the goal is the phone number.',
                'icon' => '📞',
                'blocks' => self::leadCapture(),
            ],
            'brand_story' => [
                'name' => 'Brand story',
                'tagline' => 'Editorial: story, video, craftsmanship, then the collection.',
                'best_for' => 'Higher-priced pieces where trust has to be built before the price is shown.',
                'icon' => '📖',
                'blocks' => self::brandStory(),
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    // ── Layouts ─────────────────────────────────────────────────────────────

    protected static function productSales(): array
    {
        return [
            self::block('hero_cta', ['hero' => [
                'eyebrow' => 'Limited stock',
                'heading' => 'The piece everyone is asking about',
                'subheading' => 'Hand-finished, hypoallergenic, and delivered to your door across Bangladesh. Pay only when it reaches you.',
                'cta_text' => 'Order now — cash on delivery',
                'cta_link' => '#buy',
                'cta2_text' => 'See the details',
                'cta2_link' => '#buy',
                'note' => 'No advance payment · Delivery in 2–4 days',
                'dark' => true,
            ]]),

            self::block('benefits', [
                'title' => 'Why customers keep coming back',
                'benefits' => [
                    ['icon' => '💵', 'title' => 'Cash on delivery', 'text' => 'Pay the courier when the parcel is in your hands. Nothing upfront.'],
                    ['icon' => '🚚', 'title' => 'Nationwide delivery', 'text' => 'Inside Dhaka in 1–2 days, anywhere in Bangladesh in 2–4.'],
                    ['icon' => '✨', 'title' => 'Checked by hand', 'text' => 'Every piece is inspected before it is packed, not after you complain.'],
                    ['icon' => '↩️', 'title' => 'Easy exchange', 'text' => 'Not what you expected? Message us and we will sort it out.'],
                ],
            ]),

            // The buy box always sells the products attached to this page.
            self::block('buy_box', ['title' => 'Get yours today']),

            self::block('countdown', ['countdown' => [
                'title' => 'This price ends soon',
                'ends_at' => now()->addDays(3)->startOfHour()->toDateTimeString(),
                'cta_text' => 'Claim the offer',
                'cta_link' => '#buy',
            ]]),

            self::block('reviews', ['title' => 'What buyers say', 'review_ids' => []]),

            self::block('faq', [
                'title' => 'Questions, answered',
                'faqs' => [
                    ['q' => 'Do I have to pay before delivery?', 'a' => 'No. This is cash on delivery — you pay the courier when the parcel reaches you.'],
                    ['q' => 'How long does delivery take?', 'a' => 'Usually 1–2 days inside Dhaka and 2–4 days elsewhere in Bangladesh.'],
                    ['q' => 'What if it does not suit me?', 'a' => 'Message us within the return window and we will arrange an exchange or a refund.'],
                    ['q' => 'Is the delivery charge extra?', 'a' => 'Yes, the courier charge is shown at checkout before you confirm — no surprises.'],
                ],
            ]),

            self::block('sticky_cta', ['sticky' => [
                'text' => 'Cash on delivery · Order in under a minute',
                'button' => 'Order now',
                'link' => '#buy',
            ]]),
        ];
    }

    protected static function flashSale(): array
    {
        return [
            self::block('countdown', ['countdown' => [
                'title' => 'Sale ends in',
                'ends_at' => now()->addDays(2)->startOfHour()->toDateTimeString(),
                'cta_text' => 'Shop the sale',
                'cta_link' => '#buy',
            ]]),

            self::block('cta_banner', ['cta' => [
                'eyebrow' => 'Limited time',
                'heading' => 'Up to 40% off',
                'subheading' => 'Our best-loved pieces, at their lowest price of the season.',
                'button_text' => 'Shop the sale',
                'button_link' => '#buy',
                'align' => 'center',
                'height' => 'md',
            ]]),

            self::block('product_carousel', [
                'title' => 'Best sellers on sale',
                'source' => 'best',
                'limit' => 8,
            ]),

            self::block('benefits', [
                'title' => '',
                'benefits' => [
                    ['icon' => '💵', 'title' => 'Cash on delivery', 'text' => 'Pay when it arrives.'],
                    ['icon' => '🚚', 'title' => 'Fast nationwide', 'text' => 'Delivered in 2–4 days.'],
                    ['icon' => '🎁', 'title' => 'Gift-ready packing', 'text' => 'Every order ships boxed.'],
                ],
            ]),

            self::block('product_carousel', [
                'title' => 'Just added',
                'source' => 'new',
                'limit' => 8,
            ]),

            self::block('sticky_cta', ['sticky' => [
                'text' => 'Sale prices end soon',
                'button' => 'Shop now',
                'link' => '#buy',
            ]]),
        ];
    }

    protected static function leadCapture(): array
    {
        return [
            self::block('hero_cta', ['hero' => [
                'eyebrow' => 'Pre-order open',
                'heading' => 'Reserve yours before it sells out',
                'subheading' => 'Leave your order and we will call you to confirm the details. No payment until it is in your hands.',
                'cta_text' => 'Reserve mine',
                'cta_link' => '#buy',
                'note' => 'We call every order to confirm before dispatch',
                'dark' => true,
            ]]),

            self::block('benefits', [
                'title' => '',
                'benefits' => [
                    ['icon' => '📞', 'title' => 'We call to confirm', 'text' => 'A real person confirms your order before anything ships.'],
                    ['icon' => '💵', 'title' => 'Nothing upfront', 'text' => 'Pay the courier on delivery, not before.'],
                    ['icon' => '🔒', 'title' => 'Your number is safe', 'text' => 'Used only for this order. No spam, ever.'],
                ],
            ]),

            self::block('buy_box', ['title' => 'Reserve yours']),

            self::block('reviews', ['title' => 'Trusted by customers across Bangladesh', 'review_ids' => []]),

            self::block('faq', [
                'title' => 'Before you order',
                'faqs' => [
                    ['q' => 'What happens after I order?', 'a' => 'We call you to confirm the address and size, then dispatch. You pay the courier on delivery.'],
                    ['q' => 'Am I committing to buy?', 'a' => 'No. You can cancel on the confirmation call at no cost.'],
                ],
            ]),

            self::block('sticky_cta', ['sticky' => [
                'text' => 'Reserve now · pay on delivery',
                'button' => 'Reserve mine',
                'link' => '#buy',
            ]]),
        ];
    }

    protected static function brandStory(): array
    {
        return [
            self::block('hero_cta', ['hero' => [
                'eyebrow' => 'Our story',
                'heading' => 'Made to be worn, not saved for later',
                'subheading' => 'Every piece we make is designed for real days — school runs, long shifts, late dinners — and built to survive all of them.',
                'cta_text' => 'Explore the collection',
                'cta_link' => '#buy',
                'dark' => false,
            ]]),

            self::block('richtext', ['html' => '<h2>Where it started</h2><p>Replace this with the story behind the brand — who started it, what was missing in the market, and why the pieces are made the way they are. Two or three short paragraphs is plenty; people read the first one and skim the rest.</p><p>Say something specific and checkable. "Plated to three microns" earns more trust than "premium quality".</p>']),

            self::block('video', ['title' => 'Behind the pieces', 'videos' => [
                ['title' => 'How it is made', 'url' => ''],
            ]]),

            self::block('benefits', [
                'title' => 'What goes into every piece',
                'benefits' => [
                    ['icon' => '🔬', 'title' => 'Hypoallergenic', 'text' => 'Nickel-free and safe for sensitive skin.'],
                    ['icon' => '✨', 'title' => 'Properly plated', 'text' => 'Thick plating that survives daily wear, not a week.'],
                    ['icon' => '🤲', 'title' => 'Finished by hand', 'text' => 'Inspected piece by piece before it is packed.'],
                    ['icon' => '♻️', 'title' => 'Made to last', 'text' => 'Care instructions in every box so it keeps its shine.'],
                ],
            ]),

            self::block('product_carousel', [
                'title' => 'From the collection',
                'source' => 'featured',
                'limit' => 8,
            ]),

            self::block('reviews', ['title' => 'In their words', 'review_ids' => []]),

            self::block('cta_banner', ['cta' => [
                'eyebrow' => '',
                'heading' => 'Find your piece',
                'subheading' => 'Cash on delivery, anywhere in Bangladesh.',
                'button_text' => 'Shop the collection',
                'button_link' => '',
                'align' => 'center',
                'height' => 'sm',
            ]]),
        ];
    }

    /** One block, always enabled — LandingController filters on that flag. */
    protected static function block(string $type, array $data): array
    {
        return ['type' => $type, 'enabled' => true] + $data;
    }
}
