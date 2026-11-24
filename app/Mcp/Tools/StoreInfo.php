<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class StoreInfo extends StoreTool
{
    protected string $name = 'store_info';

    protected string $description = 'Who this connector acts as and what it may do: store name and address, currency, timezone, the admin user and role behind the token, and which areas (products, orders, customers…) that role can reach. Call this first if unsure.';

    protected function run(Request $request): Response
    {
        /** @var User $user */
        $user = Auth::user();
        $sections = ['products', 'categories', 'collections', 'menu', 'orders', 'customers', 'coupons', 'offers', 'reviews', 'dashboard'];

        return $this->json([
            'store' => store_name(),
            'url' => url('/'),
            'admin_url' => url('/admin'),
            'currency' => config('store.currency', 'BDT'),
            'timezone' => config('store.timezone', 'Asia/Dhaka'),
            'acting_as' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'can_reach' => array_values(array_filter($sections, fn ($s) => $user->canAccess($s))),
            'order_statuses' => array_keys(\App\Models\Order::STATUSES),
        ]);
    }
}
