<?php

namespace App\Mcp\Tools\Orders;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetOrder extends OrderTool
{
    protected string $name = 'get_order';

    protected string $description = 'One order in full: customer and delivery address, line items, money breakdown, payment, courier consignment and the status history.';

    public function schema(JsonSchema $schema): array
    {
        return ['order' => $schema->string()->description('Order number (e.g. 1042) or id.')->required()];
    }

    protected function run(Request $request): Response
    {
        $order = $this->order((string) $this->need($request, 'order'));
        $order->load(['items', 'history', 'shipment']);
        $tz = config('store.timezone', 'Asia/Dhaka');

        return $this->json(self::summary($order) + [
            'customer_email' => $order->customer_email,
            'customer_id' => $order->customer_id,
            'address' => array_filter([
                'address' => $order->shipping_address, 'area' => $order->area,
                'city' => $order->city, 'district' => $order->district,
            ]),
            'inside_dhaka' => (bool) $order->is_inside_dhaka,
            'money' => [
                'subtotal' => (float) $order->subtotal,
                'shipping' => (float) $order->shipping_cost,
                'discount' => (float) $order->discount,
                'member_discount' => (float) $order->member_discount,
                'points_discount' => (float) $order->points_discount,
                'wallet' => (float) $order->wallet_amount,
                'total' => (float) $order->total,
                'coupon' => $order->coupon_code,
            ],
            'line_items' => $order->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'sku' => $i->sku,
                'options' => $i->attributes,
                'price' => (float) $i->price,
                'quantity' => (int) $i->quantity,
                'subtotal' => (float) $i->subtotal,
            ])->all(),
            'customer_note' => $order->notes,
            'admin_note' => $order->admin_notes,
            'is_gift' => (bool) $order->is_gift,
            'status_locked' => $order->isStatusLocked(),
            'courier' => $order->shipment ? [
                'courier' => $order->shipment->courier,
                'consignment_id' => $order->shipment->consignment_id,
                'tracking_code' => $order->shipment->tracking_code,
                'status' => $order->shipment->status,
            ] : null,
            'history' => $order->history->sortBy('id')->values()->map(fn ($h) => [
                'status' => $h->status,
                'note' => $h->note,
                'by' => $h->created_by,
                'at' => $h->created_at?->timezone($tz)->toDateTimeString(),
            ])->all(),
            'admin_url' => route('admin.orders.show', $order),
        ]);
    }
}
