<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerInsight;
use App\Services\SmsService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->query('sort', 'spend');

        $customers = Customer::query()
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->when($request->boolean('repeat'), fn ($q) => $q->where('total_orders', '>', 1))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('blacklisted', true))
            ->when($request->boolean('members'), fn ($q) => $q->whereNotNull('password'))
            ->when($request->boolean('has_points'), fn ($q) => $q->where('points', '>', 0))
            ->when($request->boolean('has_email'), fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->when($request->boolean('new_month'), fn ($q) => $q->where('created_at', '>=', now()->startOfMonth()))
            ->when($request->filled('min_spend'), fn ($q) => $q->where('total_spent', '>=', (float) $request->query('min_spend')))
            ->when($request->filled('max_spend'), fn ($q) => $q->where('total_spent', '<=', (float) $request->query('max_spend')))
            ->when($request->filled('min_orders'), fn ($q) => $q->where('total_orders', '>=', (int) $request->query('min_orders')))
            // Lapsed = has ordered but not in the last N days (default 30 when toggled).
            ->when($request->boolean('lapsed'), fn ($q) => $q->where('total_orders', '>', 0)
                ->where('last_order_at', '<', now()->subDays((int) ($request->query('lapsed_days') ?: 30))))
            ->orderBy(match ($sort) {
                'orders' => 'total_orders',
                'recent' => 'last_order_at',
                'points' => 'points',
                'name' => 'name',
                default => 'total_spent',
            }, $sort === 'name' ? 'asc' : 'desc')
            ->paginate(30)
            ->withQueryString();

        // Top-line analytics across the whole customer base.
        $analytics = [
            'total' => Customer::count(),
            'repeat' => Customer::where('total_orders', '>', 1)->count(),
            'members' => Customer::whereNotNull('password')->count(),
            'new_month' => Customer::where('created_at', '>=', now()->startOfMonth())->count(),
            'avg_spend' => round((float) Customer::where('total_orders', '>', 0)->avg('total_spent'), 0),
            'lifetime' => (float) Customer::sum('total_spent'),
            'blacklisted' => Customer::where('blacklisted', true)->count(),
        ];

        return view('admin.customers.index', compact('customers', 'analytics', 'sort'));
    }

    public function show(Customer $customer, CustomerInsight $insight)
    {
        $orders = $customer->orders()->with('shipment')->latest()->get();

        return view('admin.customers.show', [
            'customer' => $customer,
            'orders' => $orders,
            'insight' => $customer->phone ? $insight->forPhone($customer->phone) : null,
            'offers' => $customer->offers()->get(),
            'pointLog' => $customer->pointTransactions()->take(20)->get(),
        ]);
    }

    /** Export customers (name, phone, address, spend…) to an Excel-friendly CSV. */
    public function export(Request $request)
    {
        $rows = Customer::query()
            ->when($request->boolean('members'), fn ($q) => $q->whereNotNull('password'))
            ->when($request->filled('min_spend'), fn ($q) => $q->where('total_spent', '>=', (float) $request->query('min_spend')))
            ->orderByDesc('total_spent')
            ->with('defaultAddress')
            ->get();

        $filename = 'noychoy-customers-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Bangla/symbols correctly
            fputcsv($out, ['Name', 'Phone', 'Email', 'Address', 'Area', 'District', 'Orders', 'Total spent', 'Points', 'Last order', 'Registered']);
            foreach ($rows as $c) {
                $a = $c->defaultAddress;
                fputcsv($out, [
                    $c->name,
                    $c->phone,
                    $c->email,
                    $a?->address,
                    $a?->area,
                    $a?->district,
                    $c->total_orders,
                    number_format((float) $c->total_spent, 2, '.', ''),
                    $c->points,
                    $c->last_order_at?->format('Y-m-d'),
                    $c->created_at?->format('Y-m-d'),
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /** Assign a personalised offer to a customer (shown in their rewards panel). */
    public function storeOffer(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:'.implode(',', array_keys(\App\Models\CustomerOffer::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:40'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $offer = $customer->offers()->create($data + ['is_active' => true]);

        // "Bonus points" offers credit immediately and stay as a record.
        if ($offer->type === 'points' && (int) $offer->value > 0) {
            app(\App\Services\LoyaltyService::class)->award($customer, (int) $offer->value, 'adjust', 'Bonus: '.$offer->title, $offer);
        }

        return back()->with('success', 'Offer added for '.$customer->name.'.');
    }

    public function destroyOffer(Customer $customer, \App\Models\CustomerOffer $offer)
    {
        abort_unless($offer->customer_id === $customer->id, 404);
        $offer->delete();

        return back()->with('success', 'Offer removed.');
    }

    /** Manually add or subtract loyalty points. */
    public function adjustPoints(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'points' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        app(\App\Services\LoyaltyService::class)->award(
            $customer, (int) $data['points'], 'adjust', $data['reason'] ?: 'Manual adjustment',
        );

        return back()->with('success', 'Points adjusted.');
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'blacklisted' => ['nullable', 'boolean'],
        ]);
        $data['blacklisted'] = $request->boolean('blacklisted');
        $customer->update($data);

        return back()->with('success', 'Customer updated.');
    }

    public function sendSms(Request $request, Customer $customer, SmsService $sms)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        if (! $customer->phone) {
            return back()->with('error', 'This customer has no phone number.');
        }
        $ok = $sms->send($customer->phone, $data['message']);

        return back()->with($ok ? 'success' : 'error', $ok ? 'SMS sent.' : 'SMS failed (check SMS settings/logs).');
    }

    public function importForm()
    {
        return view('admin.customers.import');
    }

    /** Bulk-import customers from a CSV. Header: name, phone, email, notes */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (! $handle) {
            return back()->with('error', 'Could not read the file.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            return back()->with('error', 'The file appears to be empty.');
        }
        $cols = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $row = array_combine($cols, array_pad($line, count($cols), null));
            $phone = preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            if (strlen($phone) < 10 || $name === '') {
                $skipped++;
                continue;
            }

            $existing = Customer::where('phone', $phone)->first();
            if ($existing) {
                $existing->update(array_filter([
                    'name' => $name,
                    'email' => trim((string) ($row['email'] ?? '')) ?: null,
                    'notes' => trim((string) ($row['notes'] ?? '')) ?: $existing->notes,
                ]));
                $updated++;
            } else {
                Customer::create([
                    'name' => $name,
                    'phone' => $phone,
                    'email' => trim((string) ($row['email'] ?? '')) ?: null,
                    'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                ]);
                $created++;
            }
        }
        fclose($handle);

        return redirect()->route('admin.customers.index')
            ->with('success', "Imported {$created} new, updated {$updated}".($skipped ? ", skipped {$skipped} invalid row(s)" : '').'.');
    }
}
