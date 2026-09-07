<?php

namespace App\Jobs;

use App\Console\Commands\RunOccasions;
use App\Models\Customer;
use App\Models\CustomerOffer;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Support\Occasions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One birthday / anniversary message to one customer: an in-app bell entry
 * plus web push for members, an SMS for anyone with a phone, and — on the
 * day, for members, when the owner set a percentage — a personal offer that
 * applies itself at checkout.
 */
class SendOccasionMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $customerId, public string $occasion, public string $kind) {}

    public function handle(SmsService $sms, NotificationService $notifications): void
    {
        $customer = Customer::find($this->customerId);
        if (! $customer || $customer->blacklisted) {
            return;
        }

        $label = strtolower(Occasions::label($this->occasion));
        $labelBn = Occasions::labelBn($this->occasion);
        $bangla = $customer->locale === 'bn';
        $link = Occasions::collectionUrl($this->occasion);
        $first = $customer->firstName();
        $offerLine = '';

        // The gift itself: a personal percentage, one use, a week — members
        // only, because a guest row has no login for it to apply to.
        if ($this->kind === 'wish' && $customer->isMember() && ($pct = Occasions::offerPercent()) > 0) {
            $offer = CustomerOffer::create([
                'customer_id' => $customer->id,
                'title' => 'Happy '.$label.' — '.rtrim(rtrim(number_format($pct, 2), '0'), '.').'% off',
                'message' => 'A little gift from '.store_name().' for your '.$label.'. It applies itself at checkout.',
                'type' => 'percent',
                'value' => $pct,
                'applies_to' => 'all',
                'expires_at' => now()->addDays(Occasions::offerDays()),
                'max_redemptions' => 1,
                'is_active' => true,
            ]);
            $offerLine = ' Your gift: '.$offer->rewardText().' any order until '.store_time($offer->expires_at)->format('d M').' — log in at checkout.';
        }

        $sent = false;

        if ($customer->isMember()) {
            try {
                $notifications->broadcast([
                    'type' => 'occasion',
                    'icon' => $this->occasion === 'birthday' ? '🎂' : '💍',
                    'title' => $this->kind === 'wish'
                        ? ($bangla ? 'শুভ '.$labelBn.', '.$first.'!' : 'Happy '.$label.', '.$first.'!')
                        : ($bangla ? $first.', আপনার '.$labelBn.' আসছে' : $first.', your '.$label.' is coming up'),
                    'body' => $this->kind === 'wish'
                        ? ($bangla ? store_name().' এর পক্ষ থেকে শুভেচ্ছা।' : 'Warm wishes from all of us at '.store_name().'.').$offerLine
                        : ($bangla ? 'দিনটির জন্য বাছাই করা কিছু গহনা দেখে নিন।' : 'We picked a few pieces for the day — have a look.'),
                    'url' => $link,
                    'cta_label' => $bangla ? 'দেখুন' : 'See the picks',
                    'recipient_ids' => [$customer->id],
                ]);
                $sent = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (Occasions::smsEnabled() && filled($customer->phone)) {
            try {
                $sent = (bool) $sms->sendTemplate('occasion_'.$this->kind, $this->pseudoOrder($customer), [
                    '{name}' => $first,
                    '{occasion}' => $label,
                    '{link}' => $link,
                    '{offer}' => $offerLine,
                ]) || $sent;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! $sent) {
            // Nothing reached them: un-stamp so tomorrow's pass (or a fixed
            // gateway) gets another go instead of a silent year-long skip.
            $customer->forceFill([RunOccasions::stampColumn($this->occasion, $this->kind) => null])->saveQuietly();
            Log::warning('Occasion message was not delivered; un-stamped.', ['customer' => $customer->id, 'occasion' => $this->occasion, 'kind' => $this->kind]);
        }
    }

    /** SmsService::sendTemplate speaks Order; hand it an unsaved stand-in. */
    protected function pseudoOrder(Customer $customer): Order
    {
        $order = new Order(['customer_name' => $customer->name ?: 'there', 'customer_phone' => $customer->phone]);
        $order->setRelation('items', collect());
        $order->order_number = '';
        $order->total = 0;

        return $order;
    }
}
