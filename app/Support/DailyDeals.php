<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Deals of the Day" — the homepage promo carousel.
 *
 * It is built from the offers that are already live under Admin → Offers
 * rather than from a second, parallel list of promos. An offer already knows
 * what it applies to (the whole order, specific categories, or specific
 * products), so promoting a product or a category is just a matter of creating
 * the offer that gives the discount — there is no way for the shop window and
 * the checkout to disagree about what is on sale.
 *
 * The section carries one end time for the whole run. When it passes the
 * section stops rendering; the countdown also hides it client-side, which
 * matters because a page served from the LiteSpeed cache can outlive the
 * deadline it was rendered before.
 */
class DailyDeals
{
    /** Beyond this the carousel is scrolling past things nobody reads. */
    public const MAX_CARDS = 8;

    /** When the run ends, or null if it has no deadline. */
    public static function endsAt(): ?Carbon
    {
        $raw = home_content('deals_ends_at');

        if (blank($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null; // a malformed date must not take the homepage down
        }
    }

    /** Switched on, and not past its deadline. */
    public static function enabled(): bool
    {
        if (! home_content('show_deals')) {
            return false;
        }

        $endsAt = static::endsAt();

        return $endsAt === null || $endsAt->isFuture();
    }

    /**
     * One card per live offer.
     *
     * @return Collection<int,array{title:string,description:?string,tag:string,discount:?string,image:?string,href:string}>
     */
    public static function cards(): Collection
    {
        if (! static::enabled()) {
            return collect();
        }

        $offers = Offer::active()
            // A members-only deal is not a deal for someone who is not signed
            // in — dangling it in front of a guest is just a dead end.
            ->when(! auth('customer')->check(), fn ($q) => $q->where('members_only', false))
            ->take(static::MAX_CARDS)
            ->get();

        return $offers->map(fn (Offer $offer) => static::card($offer))->values();
    }

    protected static function card(Offer $offer): array
    {
        [$image, $href] = static::target($offer);

        return [
            'title' => $offer->title,
            'description' => $offer->description,
            'tag' => filled($offer->badge_label)
                ? $offer->badge_label
                : (Offer::TYPES[$offer->type] ?? 'Offer'),
            'discount' => static::discountLabel($offer),
            'image' => $image,
            'href' => $href,
        ];
    }

    /** The short "20% OFF" line, or null when there is no single number to show. */
    protected static function discountLabel(Offer $offer): ?string
    {
        if ($offer->type === 'free_shipping') {
            return 'Free delivery';
        }

        $percent = (float) $offer->percent;

        return $percent > 0 ? rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'% OFF' : null;
    }

    /**
     * Where the card's picture comes from and where clicking it goes.
     *
     * An offer scoped to exactly one product or category points straight at it.
     * Anything broader than that — several products, several categories, or a
     * whole-order offer — has no single right destination, so it goes to the
     * shop and borrows a picture from the newest thing on sale.
     *
     * @return array{0:?string,1:string}
     */
    protected static function target(Offer $offer): array
    {
        if ($offer->applies_to === 'products') {
            $ids = array_values(array_filter((array) $offer->product_ids));
            if (count($ids) === 1 && ($product = Product::published()->find($ids[0]))) {
                return [$product->thumbnail, route('product.show', $product)];
            }
            if ($ids && ($product = Product::published()->whereIn('id', $ids)->latest()->first())) {
                return [$product->thumbnail, route('shop')];
            }
        }

        if ($offer->applies_to === 'categories') {
            $ids = array_values(array_filter((array) $offer->category_ids));
            $category = count($ids) === 1 ? Category::find($ids[0]) : null;

            if ($category) {
                return [
                    theme_asset($category->image) ?: static::newestThumbnailIn($ids),
                    route('category.show', $category),
                ];
            }
            if ($ids) {
                return [static::newestThumbnailIn($ids), route('shop')];
            }
        }

        return [Product::published()->latest()->first()?->thumbnail, route('shop')];
    }

    /** Newest published product photo from any of the given categories. */
    protected static function newestThumbnailIn(array $categoryIds): ?string
    {
        return Product::published()
            ->where(fn ($q) => $q->whereIn('category_id', $categoryIds)
                ->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)))
            ->latest()
            ->first()?->thumbnail;
    }
}
