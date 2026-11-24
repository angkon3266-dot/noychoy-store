<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ImageOptimizer;
use App\Support\Storefront\SizeChart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
                'images' => SizeChart::images($kind),
            ]])->all(),
        ]);
    }

    public function update(Request $request, ImageOptimizer $optimizer)
    {
        $imageFields = collect(['ring', 'bangle'])->crossJoin(SizeChart::IMAGE_SLOTS)->map(fn ($p) => "{$p[0]}_{$p[1]}_image");

        $data = $request->validate([
            'ring' => ['nullable', 'array', 'max:40'],
            'ring.*.size' => ['nullable', 'string', 'max:12'],
            'ring.*.around' => ['nullable', 'numeric'],
            'bangle' => ['nullable', 'array', 'max:40'],
            'bangle.*.size' => ['nullable', 'string', 'max:12'],
            'bangle.*.around' => ['nullable', 'numeric'],
        ] + $imageFields->mapWithKeys(fn ($f) => [$f => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']])->all(),
            $imageFields->flatMap(fn ($f) => [
                "{$f}.image" => 'A picture must be a JPG, PNG or WebP image.',
                "{$f}.mimes" => 'A picture must be a JPG, PNG or WebP image.',
                "{$f}.max" => 'A picture can be at most 10 MB.',
            ])->all());

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

        // The pictures: a new upload replaces the old file, "remove" clears it.
        $images = (array) (Setting::get(SizeChart::IMAGES_KEY) ?? []);
        foreach (['ring', 'bangle'] as $kind) {
            foreach (SizeChart::IMAGE_SLOTS as $slot) {
                $field = "{$kind}_{$slot}_image";
                $old = $images[$kind][$slot] ?? null;
                if ($request->hasFile($field)) {
                    $images[$kind][$slot] = $optimizer->storeWebp($request->file($field), 'size-guide', 1600, 85);
                } elseif ($request->boolean("remove_{$field}")) {
                    $images[$kind][$slot] = null;
                } else {
                    continue;
                }
                if ($old && ! str_starts_with($old, 'http')) {
                    Storage::disk('public')->delete($old);
                }
            }
        }
        Setting::put(SizeChart::IMAGES_KEY, $images);

        $message = 'Size chart saved — "Find my size" now answers from it.';
        if ($dropped > 0) {
            $message .= " {$dropped} row(s) were left out: a size needs a name and a measurement in range, and each size only once.";
        }

        return redirect()->route('admin.products.size-chart')->with('success', $message);
    }
}
