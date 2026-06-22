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
            ->orderBy(match ($sort) {
                'orders' => 'total_orders',
                'recent' => 'last_order_at',
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
        ]);
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
