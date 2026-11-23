<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductLove;
use App\Models\WishlistShare;
use App\Support\Storefront\ProductCardData;
use App\Support\Storefront\WishlistPieces;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The shareable wishlist (Nov 2026): /wishlist for everyone — guests love
 * pieces too, and until then only members could see theirs — and a share
 * link, /wishlist/{code}, for whoever is buying the gift.
 */
class WishlistController extends Controller
{
    /** The visitor's own wishlist. */
    public function show(Request $request)
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

        return $this->render($ids, null);
    }

    /** Make (or reuse) the share link for this visitor's wishlist. */
    public function share(Request $request)
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

    public function shared(string $code)
    {
        $share = WishlistShare::where('code', $code)->firstOrFail();

        return $this->render($share->productIds(), $share);
    }

    protected function render(array $ids, ?WishlistShare $share)
    {
        $products = $this->productsInOrder($ids);
        $title = $share ? (($share->first_name ? $share->first_name.'’s' : 'A').' wishlist') : 'Your wishlist';

        return Inertia::render('Wishlist', [
            'pageTitle' => $title,
            'shared' => $share ? ['owner' => $share->first_name] : null,
            'cards' => ProductCardData::collection($products),
            'pieces' => $products->map(fn ($p) => WishlistPieces::make($p))->values(),
            'shareUrl' => route('wishlist.share'),
            'customerName' => auth('customer')->user()?->name,
        ])->withViewData(['pageTitle' => $title, 'metaRobots' => 'noindex, follow']);
    }

    /** @return Collection<int, Product> */
    protected function productsInOrder(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $byId = Product::published()
            ->with(['images', 'approvedReviews', 'category', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();
    }
}
