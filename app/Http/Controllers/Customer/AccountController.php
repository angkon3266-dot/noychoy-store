<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;

class AccountController extends Controller
{
    public function index()
    {
        $customer = auth('customer')->user();
        $orders = $customer->orders()->latest()->take(10)->get();

        return view('customer.account', compact('customer', 'orders'));
    }

    public function orders()
    {
        $orders = auth('customer')->user()->orders()->latest()->paginate(15);

        return view('customer.orders', compact('orders'));
    }

    public function order(string $orderNumber)
    {
        $order = auth('customer')->user()->orders()
            ->where('order_number', $orderNumber)
            ->with(['items', 'shipment', 'history'])
            ->firstOrFail();

        return view('customer.order', compact('order'));
    }
}
