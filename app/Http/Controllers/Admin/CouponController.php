<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRecipient;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    /**
     * The most phone numbers the coupon form writes out for editing.
     *
     * Up to this many, the "Customer phone numbers" box IS the list: a number
     * deleted from it is removed on save. A longer list is a customer group
     * added from the tools under the form, and nobody edits thousands of
     * numbers in a textarea — so it is not written out, and the box only adds
     * to it. Otherwise saving the form with the box as it looks (empty) would
     * wipe a list the owner could not even see.
     */
    public const PHONE_BOX_LIMIT = 300;

    public function index(Request $request)
    {
        $editing = $request->filled('edit') ? Coupon::find($request->query('edit')) : null;

        return view('admin.coupons.index', [
            // The count feeds the "📱 N numbers" badge in the list, in the one
            // query rather than one per row.
            'coupons' => Coupon::latest()->withCount('recipients')->paginate(30),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'editing' => $editing,
            'phoneBox' => $this->phoneBox($editing),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        [$coupon, $written] = DB::transaction(function () use ($request, $data) {
            $list = $this->phoneListFrom($request, null);
            $coupon = Coupon::create($this->withListAudience($data, $list));

            return [$coupon, $this->writePhoneList($coupon, $list)];
        });

        return $this->saved($coupon, $written, 'Coupon created');
    }

    public function update(Request $request, Coupon $coupon)
    {
        $data = $this->validateData($request, $coupon);

        $written = DB::transaction(function () use ($request, $coupon, $data) {
            // Read before the update: whether this was a phone list, and how
            // long that list is, decide what an empty box means.
            $list = $this->phoneListFrom($request, $coupon);
            $coupon->update($this->withListAudience($data, $list));

            return $this->writePhoneList($coupon, $list);
        });

        return $this->saved($coupon, $written, 'Coupon updated');
    }

    /**
     * What the "Customer phone numbers" box shows for the coupon being edited.
     *
     * Only a live list is written out. Numbers left behind on a coupon that
     * has since stopped being a phone list are dormant, and putting them back
     * in the box would bring them back to life on the next save.
     *
     * @return array{count: int, append: bool, text: string}
     */
    protected function phoneBox(?Coupon $coupon): array
    {
        $count = $coupon?->audience === 'phones' ? $coupon->recipients()->count() : 0;
        $append = $count > self::PHONE_BOX_LIMIT;

        return [
            'count' => $count,
            'append' => $append,
            'text' => ($count > 0 && ! $append)
                ? $coupon->recipients()->orderBy('id')->pluck('phone')->implode("\n")
                : '',
        ];
    }

    /**
     * What the phone box on the submitted form asks for — null when the form
     * had no box, which is a page opened before the box existed and is saved
     * exactly as it would have been then.
     *
     * The page says which mode it rendered (the hidden recipient_phones_mode),
     * but the count is checked here as well: a list can outgrow the limit in
     * another tab between opening the form and saving it, and a stale "sync"
     * must not delete what that tab added.
     *
     * @return array{numbers: array<string, ?string>, append: bool, was_list: bool}|null
     */
    protected function phoneListFrom(Request $request, ?Coupon $coupon): ?array
    {
        if (! $request->has('recipient_phones')) {
            return null;
        }

        $wasList = $coupon?->audience === 'phones';

        return [
            'numbers' => $this->readPhones((string) $request->input('recipient_phones'))[0],
            'append' => $wasList && ($request->input('recipient_phones_mode') === 'append'
                || $coupon->recipients()->count() > self::PHONE_BOX_LIMIT),
            'was_list' => $wasList,
        ];
    }

    /**
     * Who the coupon is for, once the phone box has had its say.
     *
     * Numbers in the box make it a phone list that applies itself — the owner
     * asked for exactly that (2026-09-17: "whenever that phone number is used,
     * the customer will get the coupon applied against that number"), so there
     * is no separate switch to forget to tick.
     *
     * Emptying the box on a coupon that was a list makes it a normal typed code:
     * auto_apply off and audience back to everyone. Never an automatic
     * every-order coupon — with the audience select hidden while the box had
     * numbers, "everyone" plus a leftover tick would hand a personal discount
     * to every order in the shop.
     *
     * @param  array{numbers: array<string, ?string>, append: bool, was_list: bool}|null  $list
     */
    protected function withListAudience(array $data, ?array $list): array
    {
        if ($list === null) {
            return $data;
        }

        if ($list['numbers'] !== [] || $list['append']) {
            return array_merge($data, ['auto_apply' => true, 'audience' => 'phones', 'audience_rules' => null]);
        }

        if ($list['was_list']) {
            return array_merge($data, ['auto_apply' => false, 'audience' => 'all', 'audience_rules' => null]);
        }

        // A code that does not apply itself has no audience to wait for. The
        // select stays in the page while hidden, so unticking "Apply
        // automatically" after choosing the phone list used to save exactly
        // that — harmless until Coupon::reservedForSomeoneElse() started
        // refusing every number missing from a list (same day), which would
        // turn it into a code nobody at all can spend.
        if (! $data['auto_apply'] && $data['audience'] === 'phones') {
            $data['audience'] = 'all';
        }

        return $data;
    }

    /**
     * Make the coupon's list match the box, or add the box to a long list.
     *
     * Numbers that stay are left untouched, so a name added from the list tools
     * survives an edit that never showed it. Nothing is written when an empty
     * box meets a coupon that was not a list: that is an ordinary coupon being
     * saved, and whatever it holds is none of this box's business.
     *
     * @param  array{numbers: array<string, ?string>, append: bool, was_list: bool}|null  $list
     * @return array{added: int, removed: int, total: int, cleared: bool}|null
     */
    protected function writePhoneList(Coupon $coupon, ?array $list): ?array
    {
        if ($list === null || ($list['numbers'] === [] && ! $list['was_list'])) {
            return null;
        }

        $removed = 0;
        if (! $list['append']) {
            // Worked out from the stored side, which a list in sync mode keeps
            // short, rather than whereNotIn(the box) — the box can hold
            // thousands of numbers, more than a query may bind.
            $gone = $coupon->recipients()->pluck('phone')->diff(array_keys($list['numbers']))->values()->all();

            foreach (array_chunk($gone, 500) as $chunk) {
                $removed += $coupon->recipients()->whereIn('phone', $chunk)->delete();
            }
        }

        $added = 0;
        $now = now();
        foreach (array_chunk($list['numbers'], 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $phone => $name) {
                $rows[] = ['coupon_id' => $coupon->id, 'phone' => $phone, 'name' => $name, 'created_at' => $now];
            }
            // Ignore, not upsert: a number already on the list keeps its row.
            $added += CouponRecipient::insertOrIgnore($rows);
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'total' => $coupon->recipients()->count(),
            'cleared' => $list['numbers'] === [] && ! $list['append'],
        ];
    }

    /**
     * Where to go after a save, and what to say about the phone list.
     *
     * A phone list with nobody on it applies to nobody and — since the list
     * also decides who may type the code — cannot be spent at all. That is how
     * a coupon meant for a saved customer group starts out, so rather than
     * dropping the owner back on a blank "New coupon" form, open the coupon
     * with its list tools and say what it is waiting for.
     *
     * @param  array{added: int, removed: int, total: int, cleared: bool}|null  $written
     */
    protected function saved(Coupon $coupon, ?array $written, string $done)
    {
        if ($coupon->audience === 'phones' && ! $coupon->recipients()->exists()) {
            return redirect()->route('admin.coupons.index', ['edit' => $coupon->id])->with('warning',
                "{$done}. {$coupon->code} is waiting for its phone numbers — add them in the box or from the list below. Until then nobody can use it.");
        }

        $numbers = fn (int $n) => number_format($n).' '.Str::plural('number', $n);

        $message = match (true) {
            $written === null => "{$done}.",
            $written['cleared'] => "{$done} — it is no longer a phone list, so {$coupon->code} is a normal code anyone can type.",
            $written['removed'] === 0 && $written['added'] === $written['total'] => "{$done} — it applies itself at checkout for {$numbers($written['total'])}.",
            $written['removed'] === 0 && $written['added'] === 0 => "{$done} — {$numbers($written['total'])} on the list.",
            default => "{$done} — {$numbers($written['total'])} on the list ("
                .implode(', ', array_filter([
                    $written['added'] ? "{$written['added']} added" : null,
                    $written['removed'] ? "{$written['removed']} removed" : null,
                ])).').',
        };

        return redirect()->route('admin.coupons.index')->with('success', $message);
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
            // Whatever cannot be read is skipped here, as it always has been:
            // this is the bulk tool, and a pasted export with a header row
            // should not be refused over it. The box on the form is strict.
            default => $this->readPhones($data['phones'] ?? '')[0],
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
                    // Characters, not bytes: a byte cut through a Bengali name
                    // leaves a string MySQL refuses to store.
                    'name' => $name ? mb_substr((string) $name, 0, 120) : null,
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
     * Phone numbers out of typed or pasted text, plus whatever could not be
     * read as one.
     *
     * Entries are split on new lines, commas and semicolons, and numbers inside
     * an entry may also be separated by spaces. A number written in groups —
     * "01712 345678", "+880 1712-345678", which is how people here write them —
     * is joined back into one instead of being read as fragments. Words beside
     * a single number are kept as that person's name ("Nadia 01712345678").
     *
     * One reader for both ways in; they differ only in the leftovers. The list
     * tools under the form skip them, and the "Customer phone numbers" box
     * refuses to save while there are any (owner, 2026-09-17) — a number
     * dropped without a word is a customer who was promised a discount and
     * does not get it at checkout. That is also why this replaced
     * the old pattern match, which read "017123456789" as 01712345678 with a
     * stray "9" for a name.
     *
     * @return array{0: array<string, ?string>, 1: list<string>} [canonical phone => name, unreadable]
     */
    protected function readPhones(string $text): array
    {
        return \App\Support\PhoneList::read($text);
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
            // The "Customer phone numbers" box. Checked here with everything
            // else, so a mistyped number is reported alongside any other
            // mistake on the form instead of only after it is fixed.
            'recipient_phones' => ['nullable', 'string', 'max:100000', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ($bad = $this->readPhones($value)[1]) === []) {
                    return;
                }

                $shown = array_map(fn ($entry) => Str::limit($entry, 40), array_slice($bad, 0, 10));
                $more = count($bad) > 10 ? ' and '.(count($bad) - 10).' more' : '';

                $fail(count($bad) === 1
                    ? 'Customer phone numbers: "'.$shown[0].'" is not a Bangladeshi mobile number (01XXXXXXXXX). Fix or remove it — nothing was saved.'
                    : 'Customer phone numbers: these are not Bangladeshi mobile numbers (01XXXXXXXXX): "'.implode('", "', $shown).'"'.$more.'. Fix or remove them — nothing was saved.');
            }],
            'recipient_phones_mode' => ['nullable', 'in:sync,append'],
        ]);

        // The list is written by store()/update(), not mass-assigned.
        unset($data['recipient_phones'], $data['recipient_phones_mode']);

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
