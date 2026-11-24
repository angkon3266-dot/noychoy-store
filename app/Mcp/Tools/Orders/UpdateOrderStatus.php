<?php

namespace App\Mcp\Tools\Orders;

use App\Actions\TransitionOrderStatus;
use App\Mcp\ToolError;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * The admin "change status" dropdown, through the same TransitionOrderStatus
 * action — stock release/re-reserve, loyalty points, history and staff push
 * all follow. Deliberately not here: the dropdown's "notify customer" SMS
 * (texting is send_customer_sms, separately confirmed) and any courier
 * booking (nothing in the connector books Steadfast).
 */
#[IsDestructive]
class UpdateOrderStatus extends OrderTool
{
    protected string $name = 'update_order_status';

    protected string $description = 'CHANGES AN ORDER — ask the store owner first. Moves an order to a new status exactly like the status dropdown in the admin panel: cancelling or marking partially delivered puts the stock back, moving out of cancelled takes it again, delivered awards loyalty points, a customer who turned on browser notifications gets the usual confirmed/shipped/delivered push, and the change is written to the order history under your admin name. It does NOT send an SMS and does NOT book a courier. An order the courier has already confirmed as delivered is locked and cannot change. Requires confirm=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order number or id.')->required(),
            'status' => $schema->string()->enum(array_keys(Order::STATUSES))->required(),
            'note' => $schema->string()->description('Shown in the order history (max 300 characters).'),
            'confirm' => $schema->boolean()->description('Must be true — only after the owner agreed.')->required(),
        ];
    }

    protected function run(Request $request): Response
    {
        $this->requireConfirm($request, 'change this order\'s status');

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Order::STATUSES))],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $order = $this->order((string) $this->need($request, 'order'));
        $from = $order->status;

        if ($order->load('shipment')->isStatusLocked() && $data['status'] !== $order->status) {
            throw new ToolError("Order {$order->order_number} is locked: the courier has confirmed delivery, so its status can no longer be changed.");
        }

        app(TransitionOrderStatus::class)->handle(
            $order, $data['status'], $data['note'] ?? 'Changed through the Claude connector', Auth::user()->name,
        );

        return $this->json([
            'order_number' => $order->order_number,
            'from' => $from,
            'to' => $order->fresh()->status,
            'customer_notified' => false,
            'courier_booked' => false,
        ]);
    }
}
