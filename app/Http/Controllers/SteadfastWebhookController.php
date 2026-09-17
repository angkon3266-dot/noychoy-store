<?php

namespace App\Http\Controllers;

use App\Actions\TransitionOrderStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives delivery-status callbacks from Steadfast (registered at
 * https://steadfast.com.bd/user/webhook/add). Updates the matching shipment
 * + order and reflects on the customer tracking page.
 */
class SteadfastWebhookController extends Controller
{
    public function handle(Request $request, SmsService $sms, SteadfastService $steadfast)
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

        // Locate the shipment by consignment id, else by the invoice it was
        // booked under, else the order by invoice. A re-booking goes out as
        // "<order number>-N" (Steadfast refuses a repeated invoice), so the
        // order lookup reads through that suffix too.
        $shipment = $consignmentId ? Shipment::where('consignment_id', $consignmentId)->first() : null;
        if (! $shipment && $invoice !== '') {
            $shipment = Shipment::where('invoice', $invoice)->latest('id')->first();
        }
        $order = $shipment?->order ?? ($invoice !== '' ? Order::forCourierInvoice($invoice) : null);

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 200); // 200 so Steadfast doesn't retry forever
        }

        // Read before the update: a replaced consignment already settled before
        // this callback (delivered before it was replaced, say) says nothing new.
        $wasSettled = (bool) $shipment?->isSettled();

        if ($shipment) {
            $shipment->update(['status' => $deliveryStatus ?: $shipment->status, 'response' => $payload]);
        }

        // Only the order's CURRENT consignment speaks for it. Once an order has
        // been booked again (owner's call, 2026-09-17) the replaced consignment
        // may well be cancelled at Steadfast — that is usually the whole point —
        // and letting that news through would cancel an order whose new parcel
        // is on its way, put its stock back and text the customer. The update
        // is still recorded on the replaced row above, so the owner can see what
        // became of it.
        //
        // Except a delivery: if nobody cancelled the old consignment and the
        // rider took THAT parcel to the door, it is the one the customer has,
        // and the order follows it — see SteadfastService::applyReplacedDelivery().
        if (! $this->speaksForOrder($order, $shipment, $invoice)) {
            if ($shipment?->isSuperseded() && ! $wasSettled
                && $steadfast->applyReplacedDelivery($order, $shipment, $deliveryStatus, 'Steadfast webhook')) {
                $this->notifySettled($order, $sms);

                return response()->json(['message' => 'ok'], 200);
            }

            Log::info('Steadfast webhook for a replaced consignment — recorded, order left alone', [
                'order' => $order->order_number,
                'consignment_id' => $consignmentId ?: null,
                'invoice' => $invoice ?: null,
            ]);

            return response()->json(['message' => 'ok'], 200);
        }

        // Settled courier outcomes (delivered / cancelled / partial) move the
        // order by themselves — see Order::statusForCourierStatus(). In-flight
        // states just track progress and never force a final status. A
        // cancellation is held back once a replaced consignment was delivered.
        $moved = $steadfast->applyCourierVerdict($order, $deliveryStatus, 'Steadfast webhook');

        // Nor does the unused consignment's movement drag an order the customer
        // already received back to "shipped".
        if (! $moved && ! $order->replacedConsignmentDelivered()) {
            // "in_review" is Steadfast's just-booked state — we already recorded
            // that ourselves as `booked` when the consignment was created, and
            // calling it "shipped" here would pull the order off the label queue
            // before anyone had printed the label. "pending" is the one that
            // means the courier actually has it and is moving.
            $progress = match ($deliveryStatus) {
                'hold' => 'processing',
                'pending' => 'shipped',
                default => null,
            };
            if ($progress && $order->status !== $progress) {
                app(TransitionOrderStatus::class)->handle(
                    $order, $progress, "Steadfast update: {$deliveryStatus}", 'Steadfast webhook',
                );
            }
        }

        // Notify the customer once the outcome is settled.
        if ($moved) {
            $this->notifySettled($order, $sms);
        }

        return response()->json(['message' => 'ok'], 200);
    }

    /** Text the customer the settled outcome the order has just moved to. */
    protected function notifySettled(Order $order, SmsService $sms): void
    {
        $final = $order->fresh();
        if ($final->status === 'delivered') {
            $sms->sendTemplate('order_delivered', $final);
        } elseif ($final->status === 'cancelled') {
            $sms->sendTemplate('order_cancelled', $final);
        }
    }

    /**
     * Whether this update is about the consignment the order is currently
     * travelling under.
     *
     * A matched shipment has to be the order's newest, un-replaced one. With no
     * matched shipment (a consignment made by hand in the Steadfast panel, or a
     * callback carrying only the invoice) the invoice has to be the one the
     * current consignment was booked under — and an order never booked through
     * here keeps the old behaviour of following its invoice.
     */
    protected function speaksForOrder(Order $order, ?Shipment $shipment, string $invoice): bool
    {
        $current = $order->shipment()->first();

        if ($shipment) {
            return ! $shipment->isSuperseded() && $current?->is($shipment);
        }

        if (! $current) {
            return true;
        }

        return $invoice !== '' && $current->bookedInvoice() === $invoice;
    }
}
