<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function index()
    {
        // Build a parent → children tree so the list reads logically.
        $top = Category::with(['children' => fn ($q) => $q->orderBy('position')->orderBy('name')])
            ->whereNull('parent_id')->orderBy('position')->orderBy('name')->get();

        $categories = collect();
        foreach ($top as $parent) {
            $categories->push($parent);
            foreach ($parent->children as $child) {
                $categories->push($child);
            }
        }
        // Safety net: include any category not captured above (e.g. orphaned child).
        $captured = $categories->pluck('id')->all();
        Category::with('parent')->whereNotIn('id', $captured)->orderBy('name')->get()
            ->each(fn ($c) => $categories->push($c));

        $parents = $top;

        return view('admin.categories.index', compact('categories', 'parents'));
    }

    public function create()
    {
        return view('admin.categories.form', [
            'category' => new Category(['is_active' => true]),
            'parents' => Category::whereNull('parent_id')->orderBy('name')->get(),
        ]);
    }

    public function edit(Category $category)
    {
        // Possible parents: every other category that isn't this one or a descendant.
        $invalid = $this->descendantIds($category)->push($category->id);

        return view('admin.categories.form', [
            'category' => $category,
            'parents' => Category::whereNotIn('id', $invalid)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, \App\Services\ImageOptimizer $optimizer)
    {
        $data = $this->validateData($request);
        if ($path = resolve_media($request, 'image', 'categories')) {
            $data['image'] = $path;
        }
        Category::create($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function update(Request $request, Category $category, \App\Services\ImageOptimizer $optimizer)
    {
        $data = $this->validateData($request, $category);

        // Prevent moving a category under itself or one of its own descendants.
        if (! empty($data['parent_id']) && $this->descendantIds($category)->push($category->id)->contains((int) $data['parent_id'])) {
            return back()->withInput()->withErrors(['parent_id' => 'A category cannot be placed under itself or its own sub-category.']);
        }

        if ($path = resolve_media($request, 'image', 'categories')) {
            // Delete the previous file only if it was a distinct stored upload
            // (not a re-picked library image that maps to the same path).
            if ($category->image && $category->image !== $path && ! str_starts_with($category->image, 'http')) {
                Storage::disk('public')->delete($category->image);
            }
            $data['image'] = $path;
        } elseif (($request->boolean('remove_image') || $request->boolean('image_cleared')) && $category->image) {
            if (! str_starts_with($category->image, 'http')) {
                Storage::disk('public')->delete($category->image);
            }
            $data['image'] = null;
        }

        $category->update($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    /** Move a category up/down among its siblings (same parent). */
    public function move(Request $request, Category $category)
    {
        $direction = $request->input('direction') === 'up' ? -1 : 1;

        $siblings = Category::where('parent_id', $category->parent_id)
            ->orderBy('position')->orderBy('name')->get()->values()->all();

        $i = collect($siblings)->search(fn ($c) => $c->id === $category->id);
        $j = $i + $direction;

        if ($i !== false && $j >= 0 && $j < count($siblings)) {
            [$siblings[$i], $siblings[$j]] = [$siblings[$j], $siblings[$i]];
            foreach ($siblings as $k => $c) {
                $c->update(['position' => $k]);
            }
        }

        return back()->with('success', 'Order updated.');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', 'Category deleted.');
    }

    /** IDs of all descendants (children, grandchildren…) of a category. */
    protected function descendantIds(Category $category): \Illuminate\Support\Collection
    {
        $ids = collect();
        foreach ($category->children as $child) {
            $ids->push($child->id);
            $ids = $ids->merge($this->descendantIds($child));
        }

        return $ids;
    }

    protected function validateData(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_preorder' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
            'product_template' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('theme.product_templates')))],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['is_preorder'] = $request->boolean('is_preorder');
        $data['position'] = $data['position'] ?? 0;
        $data['product_template'] = $data['product_template'] ?: null;
        $data['parent_id'] = $data['parent_id'] ?: null;
        unset($data['image']); // handled by caller

        return $data;
    }
}
