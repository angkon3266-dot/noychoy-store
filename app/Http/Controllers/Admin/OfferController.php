<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.offers.index', [
            'offers' => Offer::orderBy('sort')->orderByDesc('id')->get(),
            'types' => Offer::TYPES,
            'scopes' => Offer::SCOPES,
            'categories' => \App\Models\Category::orderBy('name')->get(['id', 'name']),
            'products' => \App\Models\Product::orderBy('name')->get(['id', 'name']),
            'editing' => $request->filled('edit') ? Offer::find($request->query('edit')) : null,
            'registerOffer' => [
                'percent' => \App\Models\Setting::get('register_offer_percent', config('loyalty.register_discount_percent', 3)),
                'text' => \App\Models\Setting::get('register_offer_text', 'Get an extra discount plus loyalty points on every order.'),
                'max_uses' => (int) \App\Models\Setting::get('register_offer_max_uses', 2),
                'window_days' => (int) \App\Models\Setting::get('register_offer_window_days', 7),
            ],
            'memberOverrides' => $this->memberOverrideRows(),
            'giftLadder' => [
                'enabled' => (bool) \App\Models\Setting::get('gift_ladder_enabled', false),
                'buy' => (int) \App\Models\Setting::get('gift_ladder_buy', 2),
                'max' => (int) \App\Models\Setting::get('gift_ladder_max', 3),
                'gifts_collection_id' => (int) \App\Models\Setting::get('gift_ladder_gifts_collection_id', 0),
                'qualifying_collection_id' => (int) \App\Models\Setting::get('gift_ladder_qualifying_collection_id', 0),
                'collections' => \App\Models\Collection::active()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'count' => app(\App\Services\CollectionService::class)->count($c)])
                    ->values(),
            ],
            'loyalty' => [
                'enabled' => (bool) \App\Models\Setting::get('loyalty_enabled', config('loyalty.enabled', true)),
                'per_1000' => round(((float) \App\Models\Setting::get('loyalty_earn_per_taka', config('loyalty.earn_per_taka', 0.1))) * 1000),
                'value_per_100' => round(((float) \App\Models\Setting::get('loyalty_redeem_value', config('loyalty.redeem_value', 0.05))) * 100, 2),
                'review' => (int) \App\Models\Setting::get('loyalty_review_points', config('loyalty.review_points', 200)),
                'signup' => (int) \App\Models\Setting::get('loyalty_signup_points', config('loyalty.signup_points', 0)),
                'photo_bonus' => (int) \App\Models\Setting::get('loyalty_review_photo_bonus', config('loyalty.review_photo_bonus', 100)),
            ],
        ]);
    }

    /** Save the loyalty/points configuration. */
    public function saveLoyalty(Request $request)
    {
        $data = $request->validate([
            'per_1000' => ['required', 'numeric', 'min:0', 'max:100000'],
            'value_per_100' => ['required', 'numeric', 'min:0', 'max:100000'],
            'review' => ['required', 'integer', 'min:0', 'max:100000'],
            'signup' => ['required', 'integer', 'min:0', 'max:100000'],
            'photo_bonus' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        \App\Models\Setting::put('loyalty_enabled', $request->boolean('enabled'));
        \App\Models\Setting::put('loyalty_earn_per_taka', (float) $data['per_1000'] / 1000);
        \App\Models\Setting::put('loyalty_redeem_value', (float) $data['value_per_100'] / 100);
        \App\Models\Setting::put('loyalty_review_points', (int) $data['review']);
        \App\Models\Setting::put('loyalty_signup_points', (int) $data['signup']);
        \App\Models\Setting::put('loyalty_review_photo_bonus', (int) $data['photo_bonus']);

        return back()->with('success', 'Loyalty settings saved.');
    }

    /**
     * Save the milestone gift ladder ("every Nth piece free").
     *
     * Enabling requires a gifts collection with at least one product in it —
     * a ladder pointing at an empty collection would advertise gifts nobody
     * can claim.
     */
    public function saveGiftLadder(Request $request)
    {
        $data = $request->validate([
            'buy' => ['required', 'integer', 'min:1', 'max:20'],
            'max' => ['required', 'integer', 'min:1', 'max:10'],
            'gifts_collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'qualifying_collection_id' => ['nullable', 'integer', 'exists:collections,id'],
        ]);

        $enabled = $request->boolean('enabled');
        $giftsId = (int) ($data['gifts_collection_id'] ?? 0);

        if ($enabled) {
            $gifts = $giftsId ? \App\Models\Collection::active()->find($giftsId) : null;
            if (! $gifts || app(\App\Services\CollectionService::class)->count($gifts) < 1) {
                return back()->with('error', 'Pick a gifts collection with at least one product before switching the ladder on.');
            }
        }

        \App\Models\Setting::put('gift_ladder_enabled', $enabled);
        \App\Models\Setting::put('gift_ladder_buy', (int) $data['buy']);
        \App\Models\Setting::put('gift_ladder_max', (int) $data['max']);
        \App\Models\Setting::put('gift_ladder_gifts_collection_id', $giftsId);
        \App\Models\Setting::put('gift_ladder_qualifying_collection_id', (int) ($data['qualifying_collection_id'] ?? 0));

        return back()->with('success', $enabled ? 'Gift ladder saved and live.' : 'Gift ladder saved (off).');
    }

    /** Save the "register for an extra discount" offer (shown to guests, applied to members). */
    public function saveRegisterOffer(Request $request)
    {
        $data = $request->validate([
            'register_offer_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'register_offer_text' => ['nullable', 'string', 'max:200'],
            'register_offer_max_uses' => ['nullable', 'integer', 'min:0', 'max:100'],
            'register_offer_window_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        \App\Models\Setting::put('register_offer_percent', (float) ($data['register_offer_percent'] ?? 0));
        \App\Models\Setting::put('register_offer_text', $data['register_offer_text'] ?? null);
        \App\Models\Setting::put('register_offer_max_uses', (int) ($data['register_offer_max_uses'] ?? 0));
        \App\Models\Setting::put('register_offer_window_days', (int) ($data['register_offer_window_days'] ?? 7));

        // Per-category / per-product member-discount overrides (from the JSON builder).
        $rows = json_decode((string) $request->input('member_overrides_json', '[]'), true);
        $overrides = ['products' => [], 'categories' => []];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $pct = max(0, min(90, (float) ($row['percent'] ?? 0)));
            if ($id <= 0) {
                continue;
            }
            if (($row['type'] ?? '') === 'product') {
                $overrides['products'][$id] = $pct;
            } elseif (($row['type'] ?? '') === 'category') {
                $overrides['categories'][$id] = $pct;
            }
        }
        \App\Models\Setting::put('member_discount_overrides', $overrides);

        return back()->with('success', 'Registration offer saved.');
    }

    /** Stored member-discount overrides → flat rows for the admin builder. */
    protected function memberOverrideRows(): array
    {
        $o = \App\Models\Setting::get('member_discount_overrides', []);
        $rows = [];
        foreach (($o['categories'] ?? []) as $id => $pct) {
            $rows[] = ['type' => 'category', 'id' => (int) $id, 'percent' => (float) $pct];
        }
        foreach (($o['products'] ?? []) as $id => $pct) {
            $rows[] = ['type' => 'product', 'id' => (int) $id, 'percent' => (float) $pct];
        }

        return $rows;
    }

    public function store(Request $request)
    {
        Offer::create($this->validateData($request));

        return redirect()->route('admin.offers.index')->with('success', 'Offer created.');
    }

    public function update(Request $request, Offer $offer)
    {
        $offer->update($this->validateData($request));

        return redirect()->route('admin.offers.index')->with('success', 'Offer updated.');
    }

    public function destroy(Offer $offer)
    {
        $offer->delete();

        return back()->with('success', 'Offer deleted.');
    }

    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:'.implode(',', array_keys(Offer::TYPES))],
            'applies_to' => ['required', 'in:'.implode(',', array_keys(Offer::SCOPES))],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'percent' => ['nullable', 'numeric', 'min:0.1', 'max:90', 'required_if:type,order_percent'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'badge_label' => ['nullable', 'string', 'max:30'],
            'offer_image' => ['nullable', 'file', 'image', 'max:5120'],
            'offer_image_url' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);

        // Deal-card picture: an upload, a pick from the media library, or
        // cleared. Absent (and not cleared) means "leave whatever is there".
        if ($image = resolve_media($request, 'offer_image', 'offers')) {
            $data['image'] = $image;
        } elseif ($request->boolean('offer_image_cleared')) {
            $data['image'] = null;
        }
        unset($data['offer_image'], $data['offer_image_url']);

        $data['members_only'] = $request->boolean('members_only');
        $data['show_on_pdp'] = $request->boolean('show_on_pdp');
        $data['is_active'] = $request->boolean('is_active');
        $data['sort'] = $data['sort'] ?? 0;
        if ($data['type'] === 'free_shipping') {
            $data['percent'] = null;
        }
        // Only keep the relevant scope list.
        $data['category_ids'] = $data['applies_to'] === 'categories' ? array_values($data['category_ids'] ?? []) : null;
        $data['product_ids'] = $data['applies_to'] === 'products' ? array_values($data['product_ids'] ?? []) : null;

        return $data;
    }
}
