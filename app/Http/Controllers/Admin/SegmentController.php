<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Services\SegmentService;
use Illuminate\Http\Request;

class SegmentController extends Controller
{
    public function index(SegmentService $segments, \App\Services\RfmService $rfm)
    {
        $list = CustomerSegment::latest()->get()->map(function ($s) use ($segments) {
            $s->member_count = $segments->count($s);

            return $s;
        });

        return view('admin.segments.index', [
            'segments' => $list,
            'editing' => request('edit') ? CustomerSegment::find(request('edit')) : null,
            'genders' => Customer::GENDERS,
            'allCustomers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'rfm' => $rfm->distribution(),
            'rfmBuckets' => $rfm->buckets(),
            'offerTypes' => \App\Models\CustomerOffer::TYPES,
        ]);
    }

    public function preview(Request $request, SegmentService $segments)
    {
        return response()->json(['count' => $segments->previewCount($request->input('rules', []))]);
    }

    public function store(Request $request)
    {
        $this->save($request, new CustomerSegment());

        return redirect()->route('admin.segments.index')->with('success', 'Segment saved.');
    }

    public function update(Request $request, CustomerSegment $segment)
    {
        $this->save($request, $segment);

        return redirect()->route('admin.segments.index')->with('success', 'Segment updated.');
    }

    protected function save(Request $request, CustomerSegment $segment): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:dynamic,manual'],
            'rules' => ['nullable', 'array'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:customers,id'],
        ]);

        $segment->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'rules' => $data['type'] === 'dynamic' ? ($data['rules'] ?? []) : null,
        ])->save();

        if ($data['type'] === 'manual') {
            $segment->members()->sync($data['member_ids'] ?? []);
        } else {
            $segment->members()->detach();
        }
    }

    /**
     * Grant a personalised member offer to every customer in a group at once.
     * Snapshots the current membership and creates one CustomerOffer per member
     * (chunked), so the offer shows on their dashboard + PDP and auto-applies at
     * checkout. Optionally fires the offer message by SMS (queued).
     */
    public function grantOffer(Request $request, CustomerSegment $segment, SegmentService $segments)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:400'],
            'type' => ['required', 'in:'.implode(',', array_keys(\App\Models\CustomerOffer::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'send_sms' => ['nullable', 'boolean'],
        ]);

        $members = $segments->query($segment)->get(['customers.id', 'customers.phone']);
        if ($members->isEmpty()) {
            return back()->with('error', 'That group has no members right now.');
        }

        $loyalty = app(\App\Services\LoyaltyService::class);
        $rows = [];
        $now = now();
        foreach ($members as $m) {
            $offer = \App\Models\CustomerOffer::create([
                'customer_id' => $m->id,
                'title' => $data['title'],
                'message' => $data['message'] ?? null,
                'type' => $data['type'],
                'value' => $data['value'] ?? 0,
                'code' => $data['code'] ?? null,
                'applies_to' => 'all',
                'expires_at' => $data['expires_at'] ?? null,
                'max_redemptions' => $data['max_redemptions'] ?? null,
                'is_active' => true,
            ]);
            // Bonus-points offers credit immediately.
            if ($offer->type === 'points' && (int) $offer->value > 0) {
                $loyalty->award(\App\Models\Customer::find($m->id), (int) $offer->value, 'adjust', 'Bonus: '.$offer->title, $offer);
            }
        }

        $smsQueued = 0;
        if ($request->boolean('send_sms') && filled($data['message'] ?? null)) {
            $phones = $members->pluck('phone')->filter()->values()->all();
            foreach (array_chunk($phones, 100) as $chunk) {
                \App\Jobs\SendSegmentSms::dispatch($chunk, $data['message']);
                $smsQueued += count($chunk);
            }
        }

        $msg = 'Offer granted to '.$members->count().' member(s) in "'.$segment->name.'".';
        if ($smsQueued > 0) {
            $msg .= " SMS queued to {$smsQueued}.";
        }

        return back()->with('success', $msg);
    }

    public function destroy(CustomerSegment $segment)
    {
        $segment->delete();

        return back()->with('success', 'Segment removed.');
    }
}
