<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerOffer;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Win-back automation: finds members who have gone quiet (no order in N days)
 * and re-engages them with an in-app notification, an optional discount offer,
 * and an optional SMS — respecting a cooldown so nobody is nudged repeatedly.
 * A no-op when the automation is off or nobody is due.
 */
class RunWinback extends Command
{
    protected $signature = 'crm:winback {--dry : List who would be targeted without sending}';

    protected $description = 'Re-engage lapsed members with a win-back notification (+ optional offer/SMS).';

    public function handle(NotificationService $notify): int
    {
        if (! (bool) Setting::get('winback_enabled', false)) {
            $this->info('Win-back automation is turned off.');

            return self::SUCCESS;
        }

        $days = max(1, (int) Setting::get('winback_days', 60));
        $cooldown = max(1, (int) Setting::get('winback_cooldown_days', 30));
        $percent = (float) Setting::get('winback_offer_percent', 0);
        $offerDays = max(1, (int) Setting::get('winback_offer_days', 14));
        $title = trim((string) Setting::get('winback_title', 'We miss you 💛')) ?: 'We miss you 💛';
        $body = trim((string) Setting::get('winback_body', 'It’s been a while — here’s a little something to welcome you back.'));

        $eligible = Customer::query()
            ->whereNotNull('password')            // registered members only
            ->where('blacklisted', false)
            ->where('total_orders', '>', 0)
            ->where('last_order_at', '<', now()->subDays($days))
            ->where(fn ($w) => $w->whereNull('winback_sent_at')->orWhere('winback_sent_at', '<', now()->subDays($cooldown)))
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No lapsed members are due for a win-back right now.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->info("Would win-back {$eligible->count()} member(s): ".$eligible->pluck('name')->implode(', '));

            return self::SUCCESS;
        }

        // Per-member offer + cooldown stamp.
        $ids = [];
        $phones = [];
        foreach ($eligible as $c) {
            if ($percent > 0) {
                CustomerOffer::create([
                    'customer_id' => $c->id,
                    'title' => 'Welcome back — '.rtrim(rtrim(number_format($percent, 2), '0'), '.').'% off',
                    'message' => $body,
                    'type' => 'percent',
                    'value' => $percent,
                    'applies_to' => 'all',
                    'expires_at' => now()->addDays($offerDays),
                    'max_redemptions' => 1,
                    'is_active' => true,
                ]);
            }
            $c->forceFill(['winback_sent_at' => now()])->saveQuietly();
            $ids[] = $c->id;
            if ($c->phone) {
                $phones[] = $c->phone;
            }
        }

        // One targeted in-app notification for the whole batch.
        $notify->broadcast([
            'type' => 'winback',
            'title' => $title,
            'body' => $body ?: null,
            'url' => route('account'),
            'cta_label' => $percent > 0 ? 'View your offer' : 'Shop now',
            'icon' => '💛',
            'recipient_ids' => $ids,
        ]);

        // Optional SMS.
        $smsQueued = 0;
        if ((bool) Setting::get('winback_sms', false) && $body !== '') {
            foreach (array_chunk($phones, 100) as $chunk) {
                \App\Jobs\SendSegmentSms::dispatch($chunk, trim($title."\n".$body));
                $smsQueued += count($chunk);
            }
        }

        $this->info("Win-back sent to {$eligible->count()} member(s)."
            .($percent > 0 ? " Offer: {$percent}% off." : '')
            .($smsQueued > 0 ? " SMS queued: {$smsQueued}." : ''));

        return self::SUCCESS;
    }
}
