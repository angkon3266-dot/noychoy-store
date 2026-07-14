<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Services\SegmentService;
use Illuminate\Http\Request;

class SegmentController extends Controller
{
    public function index(SegmentService $segments)
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

    public function destroy(CustomerSegment $segment)
    {
        $segment->delete();

        return back()->with('success', 'Segment removed.');
    }
}
