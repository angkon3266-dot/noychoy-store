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

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('categories', 'public');
        }
        Category::create($data);

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validateData($request, $category);
        if ($request->hasFile('image')) {
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }
            $data['image'] = $request->file('image')->store('categories', 'public');
        }
        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    protected function validateData(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
            'product_template' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('theme.product_templates')))],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['position'] = $data['position'] ?? 0;
        $data['product_template'] = $data['product_template'] ?: null;
        unset($data['image']); // handled by caller

        return $data;
    }
}
