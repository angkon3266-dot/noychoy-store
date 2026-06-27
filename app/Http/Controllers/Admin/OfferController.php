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
            ],
            'loyalty' => [
                'enabled' => (bool) \App\Models\Setting::get('loyalty_enabled', config('loyalty.enabled', true)),
                'per_1000' => round(((float) \App\Models\Setting::get('loyalty_earn_per_taka', config('loyalty.earn_per_taka', 0.1))) * 1000),
                'value_per_100' => round(((float) \App\Models\Setting::get('loyalty_redeem_value', config('loyalty.redeem_value', 0.05))) * 100, 2),
                'review' => (int) \App\Models\Setting::get('loyalty_review_points', config('loyalty.review_points', 200)),
                'share' => (int) \App\Models\Setting::get('loyalty_share_points', config('loyalty.share_points', 100)),
                'signup' => (int) \App\Models\Setting::get('loyalty_signup_points', config('loyalty.signup_points', 0)),
                'referral' => (int) \App\Models\Setting::get('loyalty_referral_points', config('loyalty.referral_points', 300)),
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
            'share' => ['required', 'integer', 'min:0', 'max:100000'],
            'signup' => ['required', 'integer', 'min:0', 'max:100000'],
            'referral' => ['required', 'integer', 'min:0', 'max:100000'],
            'photo_bonus' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        \App\Models\Setting::put('loyalty_enabled', $request->boolean('enabled'));
        \App\Models\Setting::put('loyalty_earn_per_taka', (float) $data['per_1000'] / 1000);
        \App\Models\Setting::put('loyalty_redeem_value', (float) $data['value_per_100'] / 100);
        \App\Models\Setting::put('loyalty_review_points', (int) $data['review']);
        \App\Models\Setting::put('loyalty_share_points', (int) $data['share']);
        \App\Models\Setting::put('loyalty_signup_points', (int) $data['signup']);
        \App\Models\Setting::put('loyalty_referral_points', (int) $data['referral']);
        \App\Models\Setting::put('loyalty_review_photo_bonus', (int) $data['photo_bonus']);

        return back()->with('success', 'Loyalty settings saved.');
    }

    /** Save the "register for an extra discount" offer (shown to guests, applied to members). */
    public function saveRegisterOffer(Request $request)
    {
        $data = $request->validate([
            'register_offer_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'register_offer_text' => ['nullable', 'string', 'max:200'],
        ]);

        \App\Models\Setting::put('register_offer_percent', (float) ($data['register_offer_percent'] ?? 0));
        \App\Models\Setting::put('register_offer_text', $data['register_offer_text'] ?? null);

        return back()->with('success', 'Registration offer saved.');
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
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);

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
