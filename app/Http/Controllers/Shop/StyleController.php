<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Look;
use App\Models\Product;
use App\Models\ProductLove;
use App\Models\WishlistShare;
use App\Support\GiftProfile;
use App\Support\Storefront\ProductCardData;
use App\Support\Storefront\StylePieces;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The style tools (Nov 2026): the shareable wishlist, the gift quiz, the
 * bangle & ring stacker, the earring try-on and Shop the look.
 */
class StyleController extends Controller
{
    /* ── Wishlist ─────────────────────────────────────────────────────────── */

    /** The visitor's own wishlist — guests included, since anyone can love a piece. */
    public function wishlist(Request $request)
    {
        $customer = auth('customer')->user();
        $token = $request->cookie('visitor_token');

        $ids = ProductLove::query()
            ->where(function ($q) use ($customer, $token) {
                $q->whereRaw('1 = 0');
                if ($customer) {
                    $q->orWhere('customer_id', $customer->id);
                }
                if ($token) {
                    $q->orWhere('visitor_token', $token);
                }
            })
            ->latest('id')->pluck('product_id')->unique()->values()->all();

        return $this->renderWishlist($ids, null);
    }

    /** Make (or reuse) the share link for this visitor's wishlist. */
    public function shareWishlist(Request $request)
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:60']]);
        $customer = auth('customer')->user();
        $token = $request->cookie('visitor_token');

        if (! $customer && ! $token) {
            return response()->json(['message' => 'Love a piece first, then share your list.'], 422);
        }

        $share = WishlistShare::query()
            ->when($customer, fn ($q) => $q->where('customer_id', $customer->id), fn ($q) => $q->where('visitor_token', $token)->whereNull('customer_id'))
            ->first();

        $share ??= new WishlistShare(['code' => WishlistShare::newCode()]);
        $share->fill([
            'customer_id' => $customer?->id,
            'visitor_token' => $token ?: $share->visitor_token,
            'owner_name' => filled($data['name'] ?? null) ? Str::limit(strip_tags($data['name']), 60, '') : ($share->owner_name ?: $customer?->name),
        ])->save();

        if ($share->productIds() === []) {
            return response()->json(['message' => 'Love a piece first, then share your list.'], 422);
        }

        return response()->json(['url' => route('wishlist.shared', $share->code)]);
    }

    public function sharedWishlist(string $code)
    {
        $share = WishlistShare::where('code', $code)->firstOrFail();

        return $this->renderWishlist($share->productIds(), $share);
    }

    protected function renderWishlist(array $ids, ?WishlistShare $share)
    {
        $products = $this->productsInOrder($ids);
        $title = $share ? (($share->first_name ? $share->first_name.'’s' : 'A').' wishlist') : 'Your wishlist';

        return Inertia::render('Wishlist', [
            'pageTitle' => $title,
            'shared' => $share ? ['owner' => $share->first_name] : null,
            'cards' => ProductCardData::collection($products),
            'pieces' => $products->map(fn ($p) => StylePieces::make($p))->values(),
            'shareUrl' => route('wishlist.share'),
            'customerName' => auth('customer')->user()?->name,
        ])->withViewData(['pageTitle' => $title, 'metaRobots' => 'noindex, follow']);
    }

    /* ── Gift quiz ────────────────────────────────────────────────────────── */

    public const STYLES = [
        'delicate' => 'Delicate, for every day',
        'statement' => 'Bold, made to be noticed',
        'classic' => 'Classic, always right',
    ];

    public const TYPES = [
        'earring' => 'Earrings',
        'necklace' => 'Necklaces',
        'ring' => 'Rings',
        'bangle' => 'Bangles & bracelets',
        'any' => 'Surprise me',
    ];

    public function giftQuiz()
    {
        return Inertia::render('GiftQuiz', [
            'pageTitle' => 'Gift finder',
            'recipients' => collect(GiftProfile::RECIPIENTS)->map(fn ($label, $key) => [$key, $label])->values(),
            'occasions' => collect(GiftProfile::OCCASIONS)->map(fn ($o, $key) => [$key, $o[0]])->values(),
            'types' => collect(self::TYPES)->map(fn ($label, $key) => [$key, $label])->values(),
            'styles' => collect(self::STYLES)->map(fn ($label, $key) => [$key, $label])->values(),
            'budgets' => collect(home_content('gift_budgets') ?? [])->map(fn ($b) => [
                'min' => $b['min'] ?? null,
                'max' => $b['max'] ?? null,
                'label' => ($b['max'] ?? null) === null ? money($b['min'] ?? 0).'+' : (($b['min'] ?? null) === null ? 'Under '.money($b['max']) : money($b['min']).' – '.money($b['max'])),
            ])->values(),
            'profile' => GiftProfile::current(),
            'resultsUrl' => route('gift-quiz.results'),
        ])->withViewData(['pageTitle' => 'Gift finder — find the right piece in five taps']);
    }

    /**
     * The shortlist. Budget and kind narrow the field; the occasion tag, the
     * style words and how loved and well reviewed a piece is put it in order.
     * When the answers leave fewer than three pieces the kind is let go before
     * the budget is — the budget is the one answer a gift buyer cannot bend.
     */
    public function giftQuizResults(Request $request)
    {
        $data = $request->validate([
            'for' => ['nullable', 'string', 'max:20'],
            'occasion' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'style' => ['nullable', 'in:'.implode(',', array_keys(self::STYLES))],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Remembered like the homepage finder's answers (session + member),
        // so "Picked for you" and the assistant know what she is shopping for.
        $request->query->set('gift', 1);
        GiftProfile::rememberFromRequest($request);

        $min = isset($data['price_min']) ? (float) $data['price_min'] : null;
        $max = isset($data['price_max']) ? (float) $data['price_max'] : null;
        $type = $data['type'] ?? 'any';
        $tag = strtolower((string) (GiftProfile::OCCASIONS[$data['occasion'] ?? ''][1] ?? ''));
        $style = $data['style'] ?? null;

        $pool = Product::published()
            ->with(['images', 'approvedReviews', 'category.parent.parent', 'categories'])
            ->get()
            ->filter(fn (Product $p) => $p->isAvailable())
            ->map(fn (Product $p) => ['p' => $p, 'price' => offer_pricing()->priceFor($p), 'kind' => StylePieces::kind($p)])
            ->filter(fn ($r) => ($min === null || $r['price'] >= $min) && ($max === null || $r['price'] <= $max));

        $wanted = fn ($r) => $type === 'any'
            || $r['kind'] === $type
            || ($type === 'bangle' && $r['kind'] === 'bracelet');
        $matched = $pool->filter($wanted);
        $relaxed = $matched->count() < 3 && $type !== 'any';
        $field = $relaxed ? $pool : $matched;

        $ranked = $field->map(function ($r) use ($tag, $style, $wanted) {
            $p = $r['p'];
            $words = strtolower($p->name.' '.$p->tags.' '.$p->category?->name);
            $score = 0.0;
            if ($tag !== '' && collect($p->tagList)->map(fn ($t) => strtolower(trim($t)))->contains($tag)) {
                $score += 3;
            }
            if ($style === 'statement' && preg_match('/statement|bridal|chandelier|cascade|maharaja|tassel|cluster|set\b/', $words)) {
                $score += 2;
            }
            if ($style === 'delicate' && preg_match('/stud|huggie|dainty|minimal|delicate|pendant|tennis|heart|petite|solitaire/', $words)) {
                $score += 2;
            }
            if ($style === 'classic' && preg_match('/pearl|gold|silver|925|solitaire|classic|tennis|hoop/', $words)) {
                $score += 2;
            }
            if ($wanted($r)) {
                $score += 2;
            }
            if (str_contains($words, 'gift')) {
                $score += 0.5;
            }
            $score += min(2, ((float) $p->average_rating) / 2.5) + min(1.5, log(1 + (int) $p->loves_count) / 3) + ($p->is_featured ? 0.5 : 0);

            return $r + ['score' => $score];
        })->sortByDesc('score')->values();

        $top = $ranked->take(6)->pluck('p');

        return response()->json([
            'cards' => ProductCardData::collection($top),
            'relaxed' => $relaxed,
            'total' => $field->count(),
            'more_url' => route('shop', array_filter(['price_min' => $min, 'price_max' => $max, 'gift' => 1, 'occasion' => $data['occasion'] ?? null], fn ($v) => $v !== null)),
        ]);
    }

    /* ── Stacker, try-on, looks ───────────────────────────────────────────── */

    public function stacker(Request $request)
    {
        $pieces = StylePieces::ofKinds(['bangle', 'bracelet', 'ring'], 80);

        return Inertia::render('Stacker', [
            'pageTitle' => 'Stack your bangles & rings',
            'pieces' => $pieces->map(fn ($p) => StylePieces::make($p))->values(),
            'start' => $request->integer('add') ?: null,
        ])->withViewData(['pageTitle' => 'Bangle & ring stacker — build your stack']);
    }

    public function tryOn(Request $request)
    {
        $pieces = StylePieces::ofKinds(['earring'], 80);

        return Inertia::render('TryOn', [
            'pageTitle' => 'Try on earrings',
            'pieces' => $pieces->map(fn ($p) => StylePieces::make($p))->values(),
            'start' => $request->integer('product') ?: null,
        ])->withViewData(['pageTitle' => 'Earring try-on — see them on a face']);
    }

    public function looks()
    {
        return Inertia::render('Looks', [
            'pageTitle' => 'Shop the look',
            'looks' => static::lookPayload(Look::active()->get()),
        ])->withViewData(['pageTitle' => 'Shop the look']);
    }

    /** Looks with their hotspots resolved to buyable pieces; dead pins drop out. */
    public static function lookPayload(Collection $looks): array
    {
        $ids = $looks->flatMap(fn (Look $l) => collect($l->cleanHotspots())->pluck('product_id'))->unique();
        $products = Product::published()->with(['images', 'variants' => fn ($q) => $q->where('is_active', true), 'category.parent', 'categories'])
            ->whereIn('id', $ids)->get()->keyBy('id');

        return $looks->map(function (Look $l) use ($products) {
            $spots = collect($l->cleanHotspots())
                ->filter(fn ($h) => $products->has($h['product_id']))
                ->map(fn ($h) => $h + ['piece' => StylePieces::make($products[$h['product_id']])])
                ->values();

            return [
                'id' => $l->id,
                'title' => $l->title,
                'caption' => $l->caption,
                'image' => $l->image_url,
                'image450' => $l->image_url ? (image_variant($l->image_url, 450) ?: $l->image_url) : null,
                'hotspots' => $spots,
            ];
        })->filter(fn ($l) => $l['image'] && count($l['hotspots']) > 0)->values()->all();
    }

    /** @return Collection<int, Product> */
    protected function productsInOrder(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $byId = Product::published()
            ->with(['images', 'approvedReviews', 'category.parent', 'categories', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();
    }
}
