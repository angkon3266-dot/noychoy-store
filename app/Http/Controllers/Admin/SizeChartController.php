<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Storefront\SizeChart;
use Illuminate\Http\Request;

/**
 * Admin → Products → Size chart (Nov 2026): the owner's own ring and bangle
 * charts, which the storefront's "Find my size" answers from. See SizeChart.
 */
class SizeChartController extends Controller
{
    public function edit()
    {
        return view('admin.products.size-chart', [
            'charts' => collect(['ring', 'bangle'])->mapWithKeys(fn ($kind) => [$kind => [
                'rows' => SizeChart::rows($kind),
                'custom' => SizeChart::isCustom($kind),
                'standard' => SizeChart::standard($kind),
                'limits' => SizeChart::LIMITS[$kind],
            ]])->all(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'ring' => ['nullable', 'array', 'max:40'],
            'ring.*.size' => ['nullable', 'string', 'max:12'],
            'ring.*.around' => ['nullable', 'numeric'],
            'bangle' => ['nullable', 'array', 'max:40'],
            'bangle.*.size' => ['nullable', 'string', 'max:12'],
            'bangle.*.around' => ['nullable', 'numeric'],
        ]);

        $saved = [];
        $dropped = 0;
        foreach (['ring', 'bangle'] as $kind) {
            $posted = collect($data[$kind] ?? [])->filter(fn ($r) => filled($r['size'] ?? null) || filled($r['around'] ?? null));
            $rows = SizeChart::clean($kind, $posted->all());
            $dropped += $posted->count() - count($rows);
            // An empty chart means "use the standard one", not "no sizes".
            if ($rows) {
                $saved[$kind] = $rows;
            }
        }

        Setting::put(SizeChart::KEY, $saved);

        $message = 'Size chart saved — "Find my size" now answers from it.';
        if ($dropped > 0) {
            $message .= " {$dropped} row(s) were left out: a size needs a name and a measurement in range, and each size only once.";
        }

        return redirect()->route('admin.products.size-chart')->with('success', $message);
    }
}
