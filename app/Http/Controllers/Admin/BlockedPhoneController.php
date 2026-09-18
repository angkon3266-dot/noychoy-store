<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedPhone;
use App\Models\Customer;
use App\Models\Order;
use App\Support\PhoneList;
use Illuminate\Http\Request;

/**
 * Numbers that may not order (owner, 2026-09-18).
 *
 * The screen takes numbers the way they arrive — pasted out of a chat, one per
 * line, with or without +880, spaces or dashes — because that is how the owner
 * has them when she decides to block someone. Anything unreadable is named
 * back rather than dropped: a number that was meant to be refused and silently
 * was not is the one failure this screen must never have.
 */
class BlockedPhoneController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : '';

        $blocked = BlockedPhone::query()
            ->when($q !== '', function ($builder) use ($q) {
                // A search box gets numbers typed the same careless way.
                $phone = bd_phone($q);

                $builder->where(function ($w) use ($q, $phone) {
                    $w->where('reason', 'like', "%{$q}%");
                    if ($phone !== '') {
                        $w->orWhere('phone', 'like', "%{$phone}%");
                    }
                });
            })
            ->with('blockedBy:id,name')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // What each blocked number cost the shop before it was blocked, so the
        // list reads as a decision rather than a pile of digits.
        $phones = $blocked->pluck('phone')->all();
        $orders = $phones === [] ? collect() : Order::whereIn('customer_phone', $phones)
            ->selectRaw('customer_phone, COUNT(*) as orders, MAX(created_at) as last_at')
            ->groupBy('customer_phone')->get()
            // MAX() comes back as a string on both drivers; the view formats it.
            ->each(fn ($row) => $row->last_at = $row->last_at ? \Illuminate\Support\Carbon::parse($row->last_at) : null)
            ->keyBy('customer_phone');
        $customers = $phones === [] ? collect() : Customer::whereIn('phone', $phones)->get(['id', 'phone', 'name'])->keyBy('phone');

        return view('admin.customers.blocked', [
            'blocked' => $blocked,
            'q' => $q,
            'orders' => $orders,
            'customers' => $customers,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'phones' => ['required', 'string', 'max:20000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        [$numbers, $unreadable] = PhoneList::read($data['phones']);

        if ($numbers === []) {
            return back()->withInput()->withErrors([
                'phones' => $unreadable === []
                    ? 'No mobile numbers in that box.'
                    : 'None of these could be read as a Bangladeshi mobile number: '.implode(', ', array_slice($unreadable, 0, 10)).'.',
            ]);
        }

        $added = 0;
        foreach (array_keys($numbers) as $phone) {
            $row = BlockedPhone::block($phone, $data['reason'] ?? null, $request->user()?->id);
            $added += $row?->wasRecentlyCreated ? 1 : 0;
        }

        $already = count($numbers) - $added;
        $message = trim(
            ($added ? $added.' number'.($added === 1 ? '' : 's').' blocked. ' : '')
            .($already ? $already.' already blocked. ' : '')
            .($unreadable !== [] ? 'Could not read: '.implode(', ', array_slice($unreadable, 0, 10)).'.' : '')
        );

        return back()->with($unreadable === [] ? 'success' : 'warning', $message);
    }

    /** Block one number from wherever it is shown — an order, a customer, a lead. */
    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        if (bd_phone($data['phone']) === '') {
            return back()->with('error', 'That is not a number this shop can block.');
        }

        BlockedPhone::block($data['phone'], $data['reason'] ?? null, $request->user()?->id);

        return back()->with('success', bd_phone($data['phone']).' can no longer place an order.');
    }

    public function destroy(BlockedPhone $blockedPhone)
    {
        $phone = $blockedPhone->phone;
        BlockedPhone::unblock($phone);

        return back()->with('success', $phone.' can order again.');
    }
}
