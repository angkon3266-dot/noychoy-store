<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Storefront\LadderQuoteData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * GET /cart/ladder-quote — the product page's reward-ladder quote, refreshed.
 *
 * The page gets `ladderQuote` and `fbt.ladder` as props when it loads, but both
 * are quoted against the cart, and the cart moves while the page stays open:
 * add a piece from the mini-cart and the next piece is no longer the "first".
 * This answers with the same two payloads, built by the same LadderQuoteData,
 * so the page can swap them in without a reload (owner's request, 17 Sep 2026).
 *
 * A quote is a read and nothing more. No Meta event, no funnel Visit row (the
 * route drops TrackVisit), no recently-viewed entry, nothing added to the cart
 * and taken away again — and no session write at all (the route carries
 * ReadOnlySession), so a quote that is still being worked out when the shopper
 * removes a piece cannot save its older cart over hers. The client sends
 * product ids only; every price comes from the database through
 * CartService::lineFor(), exactly as an add would.
 */
class LadderQuoteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Laravel remembers every plain GET as the session's "previous URL",
        // and back() prefers that over the Referer. Were this fetched without
        // the XHR header, the next form that redirects back — a review, a
        // coupon — would land the shopper on raw JSON. A quote is never a page
        // to come back to, so it always reads as the background call it is.
        // (ReadOnlySession now keeps the route from saving the session at all;
        // this stays so the answer does not hang on middleware order alone.)
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $validator = Validator::make($request->query(), [
            'product' => ['required', 'integer', 'min:1'],
            'fbt' => ['nullable', 'array', 'max:'.LadderQuoteData::MAX_FBT],
            'fbt.*' => ['integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->respond(['message' => 'Invalid quote request.', 'errors' => $validator->errors()], 422);
        }

        // Unpublished since the page loaded, or never there: nothing to quote
        // for it. Answered in the usual shape rather than as an error, because
        // "this piece cannot be bought" is exactly what a null ladderQuote
        // already says, and the page should drop a stale promise, not keep it.
        $product = Product::published()
            ->with(['images', 'category', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->find((int) $request->query('product'));

        // The tiles in the order the page shows them — the piece-by-piece split
        // depends on it. The other tiles can still be bought whatever became of
        // the page's own piece, so they are quoted either way.
        $fbtLadder = null;
        $fbtIds = collect((array) $request->query('fbt', []))->map(fn ($id) => (int) $id)->unique()->values();
        if ($fbtIds->isNotEmpty()) {
            $found = Product::published()->with(['images', 'category'])
                ->whereIn('id', $fbtIds)->get()->keyBy('id');

            $fbtLadder = LadderQuoteData::fbt($fbtIds->map(fn ($id) => $found->get($id))->filter()->values());
        }

        return $this->respond([
            'ladderQuote' => $product ? LadderQuoteData::product($product) : null,
            'fbtLadder' => $fbtLadder,
        ]);
    }

    /** Priced from this visitor's own cart, so never cached by anyone. */
    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
