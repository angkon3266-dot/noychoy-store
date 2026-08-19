<?php

namespace App\Support\Storefront;

use App\Models\Offer;
use App\Models\Product;
use App\Services\LoyaltyService;
use App\Services\WebPushService;
use Illuminate\Support\Collection;

/**
 * Everything the React product page renders, in one serializable payload.
 * Mirrors the data the Blade product templates + partials used to compute
 * inline (showcase template + _purchase/_gallery/_extras/_sticky).
 */
class ProductPageData
{
    public static function make(
        Product $product,
        Collection $related,
        Collection $crossSells,
        array $pp,
        int $lovesCount,
        bool $loved,
        string $vcEventId,
        Collection $recentlyViewed,
    ): array {
        $preorder = $product->isPreorder();
        $offerTiers = $product->offerTiers();

        $pdpOffers = Offer::active()->where('show_on_pdp', true)->get()
            ->filter(fn ($o) => $o->appliesToProduct($product))->values();

        $customer = auth('customer')->user();
        $myOffers = $customer
            ? $customer->offers()->live()->get()->filter(fn ($o) => $o->appliesToProduct($product))->values()
            : collect();

        $memberPct = (is_member() && member_pricing()->enabled())
            ? member_pricing()->percentForProduct($product) : 0;

        $loyalty = app(LoyaltyService::class);

        $reviews = $product->approvedReviews;
        $count = (int) $product->review_count;

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'url' => route('product.show', $product),
                'short_description' => $product->short_description,
                'description' => $product->description,
                'price' => (float) $product->price,
                'price_text' => money($product->price),
                'images' => $product->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url])->values(),
                'videos' => array_values($product->galleryVideos()),
                'sections' => $product->content_sections ?? [],
                'specs' => collect($product->customFieldList())->where('show', true)->values()
                    ->map(fn ($s) => ['label' => $s['label'], 'value' => $s['value']]),
                'available' => $product->isAvailable(),
                'preorder' => $preorder ? [
                    'note' => $product->preorder_note ?: 'Reserve yours now — booked items ship within about 2 weeks.',
                    'eta' => ($product->preorder_release_date ?: now()->addDays(14))->format('d M Y'),
                ] : null,
                'low_stock' => theme('urgency_low_stock') && $product->manage_stock
                    && $product->stock_quantity > 0
                    && $product->stock_quantity <= (int) theme('low_stock_threshold', 5)
                        ? (int) $product->stock_quantity : null,
                'category' => $product->category ? [
                    'name' => $product->category->name,
                    'url' => route('category.show', $product->category),
                ] : null,
                'meta_content_id' => meta_content_id($product),
                'add_url' => route('cart.add', $product),
                'buynow_url' => route('cart.buynow', $product),
                'review_url' => route('review.store', $product),
                'love_url' => route('product.love', $product),
            ],
            'pp' => $pp,
            'offerTiers' => collect($offerTiers)->map(function ($tier) use ($product) {
                $each = round((float) $product->price * (1 - $tier['percent'] / 100), 2);

                return $tier + [
                    'each_text' => money($each),
                    'price_text' => money($product->price),
                    'save_total_text' => money($tier['save_each'] * max(1, $tier['min_qty'])),
                    'save_each_text' => money($tier['save_each']),
                ];
            })->values()->all(),
            'pdpOffers' => $pdpOffers->map(fn ($o) => [
                'badge' => $o->badge_label,
                'title' => $o->title,
                'description' => $o->description,
                'members_only' => (bool) $o->members_only,
            ]),
            'myOffers' => $myOffers->map(fn ($o) => [
                'reward' => $o->rewardText(),
                'title' => $o->title,
                'message' => $o->message,
                'until' => $o->expires_at?->format('d M Y'),
            ]),
            'memberBanner' => $memberPct > 0 ? [
                'price_text' => money(member_pricing()->memberPrice($product)),
                'pct' => rtrim(rtrim(number_format($memberPct, 1), '0'), '.'),
                'savings_text' => $product->has_variants ? null : money(member_pricing()->savings($product)),
            ] : null,
            'delivery' => theme('show_delivery_estimate') ? [
                'from' => now()->addDays($dmin = (int) theme('delivery_days_min', 2))->format('D, d M'),
                'to' => ($dmax = (int) theme('delivery_days_max', 4)) > $dmin
                    ? now()->addDays($dmax)->format('d M') : null,
            ] : null,
            'reviews' => [
                'avg' => $product->average_rating,
                'count' => $count,
                'dist' => collect(range(5, 1))->mapWithKeys(fn ($s) => [$s => $reviews->where('rating', $s)->count()]),
                'items' => $reviews->take(8)->map(fn ($r) => [
                    'rating' => (int) $r->rating,
                    'author' => $r->author_name,
                    'verified' => (bool) $r->is_verified_buyer,
                    'date' => $r->created_at->format('d M Y'),
                    'title' => $r->title,
                    'body' => $r->body,
                    'photos' => $r->photo_urls ?: [],
                ])->values(),
                'perk' => $loyalty->enabled() ? $loyalty->reviewPoints() : 0,
                'photoPerk' => $loyalty->enabled() ? $loyalty->reviewPhotoBonus() : 0,
            ],
            'fbt' => theme('show_frequently_bought') && $crossSells->isNotEmpty() ? [
                'items' => collect([$product])->merge($crossSells)->take(4)->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'url' => route('product.show', $p),
                    'thumb' => $p->thumbnail,
                    'price' => (float) $p->price,
                    'price_text' => money($p->price),
                    'selectable' => $p->isAvailable() && ! $p->has_variants,
                    'has_variants' => (bool) $p->has_variants,
                ])->values(),
                'add_many_url' => route('cart.add-many'),
            ] : null,
            'related' => ProductCardData::collection($related),
            'recentlyViewed' => ProductCardData::collection($recentlyViewed),
            'loved' => $loved,
            'lovesCount' => $lovesCount,
            'vcEventId' => $vcEventId,
            'ui' => [
                'stickyBar' => (bool) theme('sticky_buy_bar'),
                'whatsapp' => theme('show_pdp_whatsapp') && theme('whatsapp_number') ? [
                    'url' => 'https://wa.me/'.preg_replace('/\D/', '', theme('whatsapp_number'))
                        .'?text='.rawurlencode('Hi! I\'m interested in "'.$product->name.'" — '.route('product.show', $product)),
                ] : null,
                'webPushReady' => app(WebPushService::class)->ready(),
                'isMember' => (bool) $customer,
                'customerName' => $customer?->name,
                'loginUrl' => route('customer.login'),
                'registerUrl' => route('customer.register'),
            ],
        ];
    }
}
