<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Services\CollectionService;
use App\Support\CollectionRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Collections CRUD. Mirrors CategoryController's shape (index / create / edit /
 * store / update / move / destroy + a shared form view), with one addition:
 * `preview` answers the rule builder's live "how many products match?" count
 * before anything is saved, the same way SegmentController does for customers.
 */
class CollectionController extends Controller
{
    public function __construct(protected CollectionService $collections) {}

    public function index()
    {
        $collections = Collection::orderBy('position')->orderBy('name')->get();

        // One count per row. Fine at collection scale (a store has tens, not
        // thousands) and it is the number the admin actually came to see.
        $counts = $collections->mapWithKeys(fn (Collection $c) => [$c->id => $this->collections->count($c)]);

        return view('admin.collections.index', compact('collections', 'counts'));
    }

    public function create()
    {
        return $this->form(new Collection([
            'type' => 'smart',
            'match' => 'all',
            'sort' => 'new',
            'is_active' => true,
            'rules' => [],
        ]));
    }

    public function edit(Collection $collection)
    {
        return $this->form($collection);
    }

    protected function form(Collection $collection)
    {
        return view('admin.collections.form', [
            'collection' => $collection,
            'fields' => CollectionRules::fields(),
            'operatorLabels' => CollectionRules::operatorLabels(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'pinned' => $collection->exists ? $collection->products()->pluck('products.id')->all() : [],
            'allProducts' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'matchCount' => $collection->exists ? $this->collections->count($collection) : 0,
        ]);
    }

    public function store(Request $request)
    {
        $collection = Collection::create($this->validateData($request));
        $this->syncPinned($collection, $request);

        return redirect()->route('admin.collections.index')->with('success', 'Collection created.');
    }

    public function update(Request $request, Collection $collection)
    {
        $collection->update($this->validateData($request, $collection));
        $this->syncPinned($collection, $request);

        return redirect()->route('admin.collections.index')->with('success', 'Collection updated.');
    }

    /** Live match count for the rule builder — nothing is saved. */
    public function preview(Request $request)
    {
        $submitted = (array) $request->input('rules', []);
        $rules = CollectionRules::sanitise($submitted);
        $match = $request->input('match') === 'any' ? 'any' : 'all';

        return response()->json([
            'count' => $this->collections->previewCount($rules, $match),
            'usable_rules' => count($rules),
            'submitted_rules' => count($submitted),
        ]);
    }

    public function move(Request $request, Collection $collection)
    {
        $delta = $request->input('direction') === 'up' ? -1 : 1;

        $siblings = Collection::orderBy('position')->orderBy('name')->get()->values()->all();
        $i = collect($siblings)->search(fn ($c) => $c->id === $collection->id);
        $j = $i + $delta;

        if ($i !== false && isset($siblings[$j])) {
            [$siblings[$i], $siblings[$j]] = [$siblings[$j], $siblings[$i]];
            foreach ($siblings as $k => $c) {
                $c->update(['position' => $k]);
            }
        }

        return back()->with('success', 'Order updated.');
    }

    public function destroy(Collection $collection)
    {
        $collection->delete();

        return redirect()->route('admin.collections.index')->with('success', 'Collection deleted.');
    }

    protected function syncPinned(Collection $collection, Request $request): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('product_ids', []))));

        $collection->products()->sync(
            collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all()
        );
    }

    protected function validateData(Request $request, ?Collection $collection = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(['smart', 'manual'])],
            'match' => ['required', Rule::in(['all', 'any'])],
            'sort' => ['nullable', Rule::in(['new', 'price_asc', 'price_desc', 'name', 'popular', 'best_selling'])],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'show_in_menu' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'rules' => ['nullable', 'array'],
            'rules.*.field' => ['required', Rule::in(CollectionRules::fieldKeys())],
            'rules.*.operator' => ['required', Rule::in(CollectionRules::allOperators())],
            'rules.*.value' => ['nullable', 'string', 'max:190'],
            'image' => ['nullable', 'file', 'image', 'max:4096'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['show_in_menu'] = $request->boolean('show_in_menu');
        $data['position'] = $data['position'] ?? 0;
        $data['sort'] = $data['sort'] ?? 'new' ?: 'new';

        // Drop malformed rows here rather than at query time, so what the admin
        // sees saved is exactly what the storefront will run.
        $data['rules'] = CollectionRules::sanitise((array) ($data['rules'] ?? []));

        // Same contract as CategoryController: `image` is validated as an upload
        // but stripped from the mass-assign array; the resolved path is set below.
        unset($data['image'], $data['product_ids']);

        if ($path = resolve_media($request, 'image', 'collections')) {
            $data['image'] = $path;
        }

        return $data;
    }
}
