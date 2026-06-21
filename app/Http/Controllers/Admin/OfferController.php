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
            'editing' => $request->filled('edit') ? Offer::find($request->query('edit')) : null,
        ]);
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

        return $data;
    }
}
