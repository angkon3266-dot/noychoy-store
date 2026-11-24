<?php

namespace App\Mcp\Tools\Customers;

use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use App\Models\Customer;

abstract class CustomerTool extends StoreTool
{
    protected ?string $section = 'customers';

    /** By id or phone number in any Bangladeshi format. */
    protected function customer(string $ref): Customer
    {
        $ref = trim($ref);
        $digits = preg_replace('/\D/', '', $ref);

        $customer = (strlen($digits) >= 10 ? Customer::where('phone', 'like', '%'.substr(bd_phone($digits), -10))->first() : null)
            ?? (ctype_digit($ref) && strlen($ref) < 10 ? Customer::find((int) $ref) : null);

        return $customer ?? throw new ToolError("No customer matches \"{$ref}\". Try find_customers.");
    }

    public static function summary(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'email' => $c->email,
            'orders' => (int) $c->total_orders,
            'total_spent' => (float) $c->total_spent,
            'points' => (int) $c->points,
            'last_order_at' => $c->last_order_at?->timezone(config('store.timezone', 'Asia/Dhaka'))->toDateTimeString(),
            'blacklisted' => (bool) $c->blacklisted,
        ];
    }
}
