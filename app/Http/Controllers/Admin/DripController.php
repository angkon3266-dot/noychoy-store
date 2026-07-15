<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerSegment;
use App\Models\DripCampaign;
use App\Services\DripService;
use Illuminate\Http\Request;

class DripController extends Controller
{
    public function index()
    {
        return view('admin.drips.index', [
            'campaigns' => DripCampaign::withCount(['steps', 'enrollments'])->latest()->get(),
            'editing' => request('edit') ? DripCampaign::with('steps')->find(request('edit')) : null,
            'segments' => CustomerSegment::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->save($request, new DripCampaign());

        return redirect()->route('admin.drips.index')->with('success', 'Drip campaign saved.');
    }

    public function update(Request $request, DripCampaign $drip)
    {
        $this->save($request, $drip);

        return redirect()->route('admin.drips.index')->with('success', 'Drip campaign updated.');
    }

    protected function save(Request $request, DripCampaign $drip): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'trigger' => ['required', 'in:registration,manual'],
            'is_active' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.delay_hours' => ['required', 'integer', 'min:0', 'max:8760'],
            'steps.*.title' => ['required', 'string', 'max:150'],
            'steps.*.body' => ['nullable', 'string', 'max:400'],
            'steps.*.url' => ['nullable', 'string', 'max:255'],
            'steps.*.image' => ['nullable', 'string', 'max:500'],
        ]);

        $drip->fill([
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'is_active' => $request->boolean('is_active'),
        ])->save();

        // Replace steps.
        $drip->steps()->delete();
        foreach (array_values($data['steps']) as $i => $s) {
            $drip->steps()->create([
                'position' => $i,
                'delay_hours' => (int) $s['delay_hours'],
                'title' => $s['title'],
                'body' => $s['body'] ?? null,
                'url' => $s['url'] ?? null,
                'image' => $s['image'] ?? null,
            ]);
        }
    }

    public function enrollSegment(Request $request, DripCampaign $drip, DripService $drips)
    {
        $data = $request->validate(['segment_id' => ['required', 'exists:customer_segments,id']]);
        $count = $drips->enrollSegment($drip, CustomerSegment::findOrFail($data['segment_id']));

        return back()->with('success', "Enrolled {$count} member(s) into “{$drip->name}”.");
    }

    public function destroy(DripCampaign $drip)
    {
        $drip->delete();

        return back()->with('success', 'Drip campaign removed.');
    }
}
