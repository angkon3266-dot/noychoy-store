<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.coupons.index', [
            'coupons' => Coupon::latest()->paginate(30),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'editing' => $request->filled('edit') ? Coupon::find($request->query('edit')) : null,
        ]);
    }

    public function store(Request $request)
    {
        Coupon::create($this->validateData($request));

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon created.');
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($this->validateData($request, $coupon));

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon updated.');
    }

    /**
     * Add phone numbers to a coupon's list.
     *
     * Three ways in, because "specific / batch / all" are the same problem at
     * three sizes: numbers pasted in by hand, everyone in a saved customer
     * group, or every past buyer. Numbers that belong to nobody yet are kept
     * deliberately — a coupon waiting for a first-time buyer is the point.
     */
    public function addRecipients(Request $request, Coupon $coupon, \App\Services\SegmentService $segments)
    {
        $data = $request->validate([
            'source' => ['required', 'in:paste,segment,buyers'],
            'phones' => ['nullable', 'string', 'max:100000'],
            'segment_id' => ['nullable', 'integer', 'exists:customer_segments,id'],
        ]);

        $rows = match ($data['source']) {
            'segment' => $this->segmentPhones($data['segment_id'] ?? null, $segments),
            'buyers' => \App\Models\Customer::where('total_orders', '>', 0)
                ->whereNotNull('phone')->where('blacklisted', false)
                ->pluck('name', 'phone')->all(),
            default => $this->pastedPhones($data['phones'] ?? ''),
        };

        if ($rows === []) {
            return back()->with('error', 'No usable phone numbers in that.');
        }

        $now = now();
        $added = 0;
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $payload = [];
            foreach ($chunk as $phone => $name) {
                $payload[] = [
                    'coupon_id' => $coupon->id,
                    'phone' => $phone,
                    'name' => $name ? substr((string) $name, 0, 120) : null,
                    'created_at' => $now,
                ];
            }
            // Re-adding a list is a no-op rather than a duplicate-key error —
            // the owner will paste an overlapping list sooner or later.
            \App\Models\CouponRecipient::upsert($payload, ['coupon_id', 'phone'], ['name']);
            $added += count($payload);
        }

        // A list is pointless on a coupon that never applies itself, and being
        // silently ignored is worse than being corrected.
        if (! $coupon->auto_apply || $coupon->audience !== 'phones') {
            $coupon->update(['auto_apply' => true, 'audience' => 'phones']);
        }

        return back()->with('success',
            "{$added} number(s) added — the coupon now applies itself for them at checkout.");
    }

    public function removeRecipient(Coupon $coupon, \App\Models\CouponRecipient $recipient)
    {
        abort_unless($recipient->coupon_id === $coupon->id, 404);
        $recipient->delete();

        return back()->with('success', 'Removed from the list.');
    }

    /** @return array<string,?string> canonical phone => name */
    protected function segmentPhones(?int $segmentId, \App\Services\SegmentService $segments): array
    {
        $segment = $segmentId ? \App\Models\CustomerSegment::find($segmentId) : null;

        if (! $segment) {
            return [];
        }

        return $segments->query($segment)
            ->whereNotNull('phone')->where('blacklisted', false)
            ->pluck('name', 'phone')->all();
    }

    /**
     * Phone numbers out of pasted text — one per line, comma or semicolon
     * separated, with or without names beside them.
     *
     * @return array<string,?string>
     */
    protected function pastedPhones(string $text): array
    {
        $out = [];

        foreach (preg_split('/[\r\n,;]+/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // "Nadia 01712345678" or "01712345678 Nadia" — take the number,
            // keep whatever is left as the name.
            if (! preg_match('/(\+?8?8?0?1[3-9]\d{8})/', $line, $m)) {
                continue;
            }

            $phone = bd_phone($m[1]);
            if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
                continue;
            }

            $name = trim(str_replace($m[1], '', $line), " \t-–—:|");
            $out[$phone] = $name !== '' ? $name : null;
        }

        return $out;
    }

    protected function nullableNumber($value)
    {
        return ($value === null || $value === '') ? null : $value + 0;
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return back()->with('success', 'Coupon deleted.');
    }

    protected function validateData(Request $request, ?Coupon $coupon = null): array
    {
        $codeRule = ['required', 'string', 'max:40'];
        $codeRule[] = 'unique:coupons,code'.($coupon ? ','.$coupon->id : '');

        $data = $request->validate([
            'code' => $codeRule,
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'applies_to' => ['required', 'in:all,categories,products'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'exclude_sale_items' => ['nullable', 'boolean'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'max_qty' => ['nullable', 'integer', 'min:1'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'free_shipping' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            // Applies itself, to whom.
            'auto_apply' => ['nullable', 'boolean'],
            'audience' => ['nullable', 'in:'.implode(',', array_keys(Coupon::AUDIENCES))],
            'audience_rules' => ['nullable', 'array'],
            'audience_rules.first_order_only' => ['nullable', 'boolean'],
            'audience_rules.members_only' => ['nullable', 'boolean'],
            'audience_rules.min_orders' => ['nullable', 'integer', 'min:1'],
            'audience_rules.min_spend' => ['nullable', 'numeric', 'min:0'],
            'audience_rules.lapsed_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active');
        $data['exclude_sale_items'] = $request->boolean('exclude_sale_items');
        $data['free_shipping'] = $request->boolean('free_shipping');
        $data['auto_apply'] = $request->boolean('auto_apply');
        $data['audience'] = $data['audience'] ?? 'all';

        // Keep only the rules that belong to the chosen audience, and drop the
        // blanks — an empty string in `min_orders` would otherwise read as a
        // rule of "at least 0 orders", which matches everybody.
        $data['audience_rules'] = $data['audience'] === 'rule'
            ? array_filter([
                'first_order_only' => $request->boolean('audience_rules.first_order_only') ?: null,
                'members_only' => $request->boolean('audience_rules.members_only') ?: null,
                'min_orders' => $this->nullableNumber($data['audience_rules']['min_orders'] ?? null),
                'min_spend' => $this->nullableNumber($data['audience_rules']['min_spend'] ?? null),
                'lapsed_days' => $this->nullableNumber($data['audience_rules']['lapsed_days'] ?? null),
            ], fn ($v) => $v !== null)
            : null;

        // Only keep the scope list that matches the chosen scope.
        $data['category_ids'] = $data['applies_to'] === 'categories' ? array_values(array_map('intval', $data['category_ids'] ?? [])) : null;
        $data['product_ids'] = $data['applies_to'] === 'products' ? array_values(array_map('intval', $data['product_ids'] ?? [])) : null;

        return $data;
    }
}
