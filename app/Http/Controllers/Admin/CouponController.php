<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.coupons.index', [
            'coupons' => Coupon::latest()->paginate(30),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'editing' => $request->filled('edit') ? Coupon::find($request->query('edit')) : null,
        ]);
    }

    public function store(Request $request)
    {
        Coupon::create($this->validateData($request));

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon created.');
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($this->validateData($request, $coupon));

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon updated.');
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return back()->with('success', 'Coupon deleted.');
    }

    protected function validateData(Request $request, ?Coupon $coupon = null): array
    {
        $codeRule = ['required', 'string', 'max:40'];
        $codeRule[] = 'unique:coupons,code'.($coupon ? ','.$coupon->id : '');

        $data = $request->validate([
            'code' => $codeRule,
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'applies_to' => ['required', 'in:all,categories,products'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'exclude_sale_items' => ['nullable', 'boolean'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'max_qty' => ['nullable', 'integer', 'min:1'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'free_shipping' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active');
        $data['exclude_sale_items'] = $request->boolean('exclude_sale_items');
        $data['free_shipping'] = $request->boolean('free_shipping');

        // Only keep the scope list that matches the chosen scope.
        $data['category_ids'] = $data['applies_to'] === 'categories' ? array_values(array_map('intval', $data['category_ids'] ?? [])) : null;
        $data['product_ids'] = $data['applies_to'] === 'products' ? array_values(array_map('intval', $data['product_ids'] ?? [])) : null;

        return $data;
    }
}
