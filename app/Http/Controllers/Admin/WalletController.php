<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Rules\BdPhone;
use App\Services\WalletService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Wallet credit per mobile number (owner, 2026-11-03). The owner adds or
 * takes off money for a number here; the checkout offers it to whoever
 * orders on that number. See App\Services\WalletService for the rules.
 */
class WalletController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : '';

        $wallets = Wallet::query()
            ->when($q !== '', function ($builder) use ($q) {
                // A name finds the number through the customer it belongs to.
                $phone = bd_phone($q);
                $builder->where(function ($w) use ($q, $phone) {
                    if ($phone !== '') {
                        $w->where('phone', 'like', "%{$phone}%");
                    }
                    $w->orWhereIn('phone', Customer::where('name', 'like', "%{$q}%")->select('phone'));
                });
            })
            ->latest('updated_at')
            ->paginate(50)
            ->withQueryString();

        $phones = $wallets->pluck('phone')->all();
        $customers = $phones === [] ? collect() : Customer::whereIn('phone', $phones)->get(['id', 'phone', 'name'])->keyBy('phone');

        // The credit to announce on each row's WhatsApp button: the last
        // money added to it, or the balance when none was added by hand.
        $lastCredit = $phones === [] ? collect() : WalletTransaction::whereIn('wallet_id', $wallets->pluck('id'))
            ->where('type', 'credit')->selectRaw('wallet_id, MAX(id) as id')->groupBy('wallet_id')->pluck('id', 'wallet_id');
        $lastCredit = $lastCredit->isEmpty() ? collect() : WalletTransaction::whereIn('id', $lastCredit->values())->pluck('amount', 'wallet_id');

        $service = app(WalletService::class);
        $whatsapp = $wallets->getCollection()->mapWithKeys(fn (Wallet $w) => [
            $w->id => $service->whatsappLink(
                $w->phone,
                (float) ($lastCredit[$w->id] ?? $w->balance),
                (float) $w->balance,
                $customers[$w->phone]->name ?? null,
            ),
        ]);

        return view('admin.customers.wallets', [
            'wallets' => $wallets,
            'whatsapp' => $whatsapp,
            'template' => $service->whatsappTemplate(),
            'templateDefault' => WalletService::WHATSAPP_DEFAULT,
            'customers' => $customers,
            'q' => $q,
            // Opened from a customer or an order: the form starts on that number.
            'phone' => bd_phone((string) $request->query('phone', '')),
            'outstanding' => (float) Wallet::sum('balance'),
            'recent' => WalletTransaction::with(['wallet:id,phone', 'order:id,order_number', 'user:id,name'])
                ->latest('id')->limit(15)->get(),
        ]);
    }

    public function store(Request $request, WalletService $wallets)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new BdPhone],
            'direction' => ['required', 'in:add,take'],
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'phone.regex' => 'Please enter a valid Bangladeshi mobile number (e.g. 01XXXXXXXXX).',
        ]);

        $amount = round((float) $data['amount'], 2) * ($data['direction'] === 'take' ? -1 : 1);

        try {
            $tx = $wallets->adjust($data['phone'], $amount, $data['note'] ?? null, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $phone = bd_phone($data['phone']);

        return back()
            ->with('success', ($amount > 0
                ? money($amount).' added to '.$phone
                : money(abs($amount)).' taken off '.$phone)
                .' — balance now '.money($tx->balance_after).'.')
            // Credit added: offer to tell them on WhatsApp, message filled in.
            ->with('wallet_whatsapp', $amount > 0 ? [
                'phone' => $phone,
                'url' => $wallets->whatsappLink($phone, $amount, (float) $tx->balance_after,
                    Customer::where('phone', $phone)->value('name')),
            ] : null);
    }

    /** The WhatsApp message, edited on the Wallets page. Blank = the default. */
    public function template(Request $request)
    {
        $data = $request->validate(['template' => ['nullable', 'string', 'max:1000']]);

        \App\Models\Setting::put('whatsapp_wallet_template', trim((string) ($data['template'] ?? '')) ?: null);

        return back()->with('success', 'WhatsApp message saved.');
    }

    public function show(Wallet $wallet)
    {
        $customer = $wallet->customer();
        $lastCredit = (float) ($wallet->transactions()->where('type', 'credit')->value('amount') ?? $wallet->balance);

        return view('admin.customers.wallet', [
            'wallet' => $wallet,
            'customer' => $customer,
            'whatsapp' => app(WalletService::class)->whatsappLink($wallet->phone, $lastCredit, (float) $wallet->balance, $customer?->name),
            'transactions' => $wallet->transactions()->with(['order:id,order_number', 'user:id,name'])->paginate(50),
        ]);
    }
}
