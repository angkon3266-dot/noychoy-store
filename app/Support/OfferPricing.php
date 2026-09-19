<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * What a piece sells for today, once the live offers are counted.
 *
 * The owner's call on 19 Sep 2026: an offer from Admin → Offers used to be a
 * note on the product page ("20% Flat Off — Auto Applied at checkout") and came
 * off only in the cart. A product it covers now LISTS at the discounted price,
 * with its regular price struck through beside it — on the cards, the product
 * page, the home blocks, the structured data, the Meta and Google feeds and in
 * the assistant's answers. Nothing is written to the product: pause or delete
 * the offer and every price is back to normal on the next page load.
 *
 * Only an offer a single piece can promise moves the price: a live percentage,
 * ticked "Show on product pages", with no minimum cart value and no minimum
 * item count (Offer::isUnconditional). One that needs ৳3,000 in the cart or a
 * second piece stays the note it always was. A members-only offer moves the
 * member price alone, for a signed-in customer (ProductCardData).
 *
 * The cart charges what the shelf shows because it prices every line by the
 * best offer covering THAT line (CartService::promoLines) — the same pick
 * offerFor() makes here.
 */
class OfferPricing
{
    /** @var Collection<int, Offer>|null */
    protected ?Collection $offers = null;

    /**
     * The offers that move a price, best first; ties keep the admin's sort
     * order. Loaded once per request — a catalogue page asks for every card.
     *
     * @return Collection<int, Offer>
     */
    public function offers(): Collection
    {
        return $this->offers ??= Offer::active()
            ->where('type', 'order_percent')
            ->where('show_on_pdp', true)
            ->get()
            ->filter(fn (Offer $o) => $o->isUnconditional())
            ->sortByDesc(fn (Offer $o) => (float) $o->percent)
            ->values();
    }

    /** Drop the loaded offers — Offer's saved/deleted hooks call this. */
    public function forget(): void
    {
        $this->offers = null;
    }

    /** Does any of them depend on a product's categories? Then load them up front. */
    public function needsCategories(): bool
    {
        return $this->offers()->contains('applies_to', 'categories');
    }

    /** The offer a product lists under: the best one every shopper gets that covers it. */
    public function offerFor(Product $product): ?Offer
    {
        return $this->offers()->first(fn (Offer $o) => ! $o->members_only && $o->appliesToProduct($product));
    }

    /** The best members-only offer covering it — what a signed-in customer gets on top. */
    public function memberOfferFor(Product $product): ?Offer
    {
        return $this->offers()->first(fn (Offer $o) => $o->members_only && $o->appliesToProduct($product));
    }

    /**
     * A piece's price and what to strike through beside it.
     *
     * With an offer, the regular price is the struck one. Without, a compare-at
     * price typed on the product (or the variant) still shows, as it always
     * did — the owner cleared every one on 19 Sep 2026 but kept the field.
     *
     * @return array{price: float, was: ?float, percent: float, offer: ?Offer}
     */
    public function quote(Product $product, ?ProductVariant $variant = null): array
    {
        // The unit the cart line will carry (CartService::lineFor: the variant's
        // own price where it has one) — read off $product rather than through
        // $variant->product, which a feed walking every variant would lazy-load.
        $unit = $variant?->price !== null ? (float) $variant->price : (float) $product->price;

        // An unpriced piece (৳0) has nothing to take a percentage of, and
        // "৳0, was ৳0, −20%" helps nobody.
        if ($unit > 0 && $offer = $this->offerFor($product)) {
            $percent = (float) $offer->percent;

            return ['price' => self::less($unit, $percent), 'was' => $unit, 'percent' => $percent, 'offer' => $offer];
        }

        $compare = $variant ? $variant->compare_at_price : $product->compare_at_price;
        $was = $compare !== null && (float) $compare > $unit ? (float) $compare : null;

        return [
            'price' => $unit,
            'was' => $was,
            'percent' => $was ? round((1 - $unit / $was) * 100) : 0.0,
            'offer' => null,
        ];
    }

    /** The listed price of a product, or of one of its variants. */
    public function priceFor(Product $product, ?ProductVariant $variant = null): float
    {
        return $this->quote($product, $variant)['price'];
    }

    /**
     * The listed price as SQL, so the catalogue filters and sorts by what the
     * cards print: "Under ৳1,000" has to include a ৳1,250 piece listed at
     * ৳1,000. One CASE branch per offer, best first — the pick offerFor()
     * makes — and plain `products.price` while no offer is live.
     *
     * Every value inlined is a number or an id cast from the offers table.
     */
    public function listedPriceSql(string $table = 'products'): string
    {
        $price = $table.'.price';
        $ids = fn ($list) => collect($list ?? [])->map(fn ($id) => (int) $id)->filter()->implode(',');

        $branches = $this->offers()->where('members_only', false)->map(function (Offer $o) use ($table, $price, $ids) {
            $when = match ($o->applies_to) {
                'products' => ($list = $ids($o->product_ids)) !== '' ? "{$table}.id IN ({$list})" : null,
                'categories' => ($list = $ids($o->category_ids)) !== ''
                    ? "({$table}.category_id IN ({$list}) OR EXISTS (SELECT 1 FROM category_product WHERE category_product.product_id = {$table}.id AND category_product.category_id IN ({$list})))"
                    : null,
                default => '1 = 1',
            };
            $percent = (float) $o->percent;

            return $when === null ? null : "WHEN {$when} THEN {$price} - ROUND({$price} * {$percent} / 100, 2)";
        })->filter();

        // Cast, so the result compares as a number: a bare CASE has no type in
        // SQLite, which then ranks every number below a bound "1000" and let
        // a ৳1,040 piece through "Under ৳1,000".
        return $branches->isEmpty()
            ? $price
            : 'CAST((CASE '.$branches->implode(' ').' ELSE '.$price.' END) AS DECIMAL(12,2))';
    }

    /** `$unit` less `$percent`, rounded to the paisa as the cart rounds a percentage. */
    public static function less(float $unit, float $percent): float
    {
        return round(max(0, $unit - round($unit * $percent / 100, 2)), 2);
    }

    /** "20" for 20.00, "12.5" for 12.50 — a percentage as a badge prints it. */
    public static function percentText(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }

    /** The same as a number for a payload: 20 stays an integer, 12.5 does not. */
    public static function percentValue(float $percent): int|float
    {
        $percent = round($percent, 2);

        return $percent == floor($percent) ? (int) $percent : $percent;
    }
}
