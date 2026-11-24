<?php

namespace App\Mcp\Tools\Orders;

use App\Mcp\ToolError;
use App\Mcp\Tools\StoreTool;
use App\Models\Order;

abstract class OrderTool extends StoreTool
{
    protected ?string $section = 'orders';

    /** By numeric id or order number ("NC-1042", "1042"). */
    protected function order(string $ref): Order
    {
        $ref = trim($ref);

        $order = Order::query()->where('order_number', $ref)->first()
            ?? (ctype_digit($ref) ? Order::find((int) $ref) : null)
            ?? Order::query()->where('order_number', 'like', '%'.$ref)->orderByDesc('id')->first();

        return $order ?? throw new ToolError("No order matches \"{$ref}\". Try list_orders with a search.");
    }

    public static function summary(Order $o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'customer_name' => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'total' => (float) $o->total,
            'payment_method' => $o->payment_method,
            'payment_status' => $o->payment_status,
            'items' => (int) ($o->items_count ?? $o->items()->count()),
            'source' => $o->source,
            'placed_at' => $o->created_at?->timezone(config('store.timezone', 'Asia/Dhaka'))->toDateTimeString(),
        ];
    }
}
