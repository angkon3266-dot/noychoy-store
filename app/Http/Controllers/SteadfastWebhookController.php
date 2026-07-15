<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives delivery-status callbacks from Steadfast (registered at
 * https://steadfast.com.bd/user/webhook/add). Updates the matching shipment
 * + order and reflects on the customer tracking page.
 */
class SteadfastWebhookController extends Controller
{
    public function handle(Request $request, SmsService $sms)
    {
        // Shared-secret check — fail CLOSED. A secret must be configured
        // ("steadfast_webhook_secret" in admin → Integrations) and match, or the
        // request is rejected. Otherwise anyone could POST fake delivery statuses
        // (flipping orders to delivered/cancelled and firing customer SMS).
        $secret = (string) data_get(Setting::get('integrations', []), 'steadfast_webhook_secret');
        $provided = (string) ($request->query('token') ?? $request->header('X-Webhook-Token') ?? '');
        if (blank($secret) || ! hash_equals($secret, $provided)) {
            Log::warning('Steadfast webhook rejected', [
                'reason' => blank($secret) ? 'no secret configured' : 'token mismatch',
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        // Log only non-PII identifiers (the full payload — which can carry the
        // customer's phone — is still persisted to shipment.response for support).
        Log::info('Steadfast webhook', [
            'consignment_id' => $payload['consignment_id'] ?? null,
            'invoice' => $payload['invoice'] ?? null,
            'delivery_status' => $payload['delivery_status'] ?? $payload['status'] ?? null,
        ]);

        $consignmentId = (string) ($payload['consignment_id'] ?? '');
        $invoice = (string) ($payload['invoice'] ?? '');
        $deliveryStatus = (string) ($payload['delivery_status'] ?? $payload['status'] ?? '');

        // Locate the shipment by consignment id, else the order by invoice.
        $shipment = $consignmentId ? Shipment::where('consignment_id', $consignmentId)->first() : null;
        $order = $shipment?->order ?? ($invoice ? Order::where('order_number', $invoice)->first() : null);

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 200); // 200 so Steadfast doesn't retry forever
        }

        if ($shipment) {
            $shipment->update(['status' => $deliveryStatus ?: $shipment->status, 'response' => $payload]);
        }

        // Map Steadfast delivery status -> store order status.
        $map = [
            'delivered' => 'delivered',
            'partial_delivered' => 'delivered',
            'cancelled' => 'cancelled',
            'hold' => 'processing',
            'in_review' => 'shipped',
            'pending' => 'shipped',
        ];
        $newStatus = $map[$deliveryStatus] ?? null;

        if ($newStatus && $order->status !== $newStatus) {
            $order->update(['status' => $newStatus]);
            $order->history()->create([
                'status' => $newStatus,
                'note' => "Steadfast update: {$deliveryStatus}",
                'created_by' => 'Steadfast webhook',
            ]);

            // Notify customer on delivery / cancellation.
            if ($newStatus === 'delivered') {
                $sms->sendTemplate('order_delivered', $order->fresh());
            } elseif ($newStatus === 'cancelled') {
                $sms->sendTemplate('order_cancelled', $order->fresh());
            }
        }

        return response()->json(['message' => 'ok'], 200);
    }
}
