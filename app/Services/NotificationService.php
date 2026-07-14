<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Setting;

/**
 * Broadcast in-app notifications to registered members. A single row per
 * notification (not per-customer); unread state is derived from each
 * customer's notifications_read_at. Web-push delivery is added in a later phase.
 */
class NotificationService
{
    /**
     * Create + deliver a broadcast (or schedule it for later).
     *
     * @param  array{type?:string,title:string,body?:string,url?:string,cta_label?:string,icon?:string,scheduled_at?:mixed,created_by?:int}  $data
     */
    public function broadcast(array $data): CustomerNotification
    {
        $scheduled = $data['scheduled_at'] ?? null;
        $recipientIds = $data['recipient_ids'] ?? null;   // array = targeted; null = all members

        // Reach snapshot for analytics: unique recipients for a segment send, or
        // the current member count for an all-member send.
        $reach = is_array($recipientIds)
            ? count(array_unique($recipientIds))
            : (int) ($data['recipients_count'] ?? Customer::whereNotNull('password')->count());

        $n = CustomerNotification::create([
            'type' => $data['type'] ?? 'announcement',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'icon' => $data['icon'] ?? null,
            'audience' => is_array($recipientIds) ? 'segment' : 'all',
            'segment_id' => $data['segment_id'] ?? null,
            'recipients_count' => $reach,
            'scheduled_at' => $scheduled,
            'sent_at' => $scheduled ? null : now(),
            'created_by' => $data['created_by'] ?? optional(auth()->user())->id,
        ]);

        if (is_array($recipientIds)) {
            foreach (array_chunk(array_values(array_unique($recipientIds)), 1000) as $chunk) {
                $n->recipients()->attach($chunk);
            }
        }

        return $n;
    }

    /** Notifications visible to a given customer (all-audience + targeted-at-them). */
    public function visibleFor(?Customer $customer): \Illuminate\Database\Eloquent\Builder
    {
        $q = CustomerNotification::whereNotNull('sent_at');
        if (! $customer) {
            return $q->where('audience', 'all');
        }

        return $q->where(fn ($w) => $w->where('audience', 'all')
            ->orWhereIn('id', fn ($sub) => $sub->from('customer_notification_recipients')
                ->select('customer_notification_id')->where('customer_id', $customer->id)));
    }

    /** Deliver any scheduled notifications whose time has arrived. */
    public function deliverDue(): int
    {
        $due = CustomerNotification::whereNull('sent_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $n) {
            $n->update(['sent_at' => now()]);
        }

        return $due->count();
    }

    /** Recent notifications visible to this customer (for the bell + dashboard). */
    public function recentFor(?Customer $customer, int $limit = 15)
    {
        return $this->visibleFor($customer)->orderByDesc('sent_at')->limit($limit)->get();
    }

    public function unreadCountFor(?Customer $customer): int
    {
        if (! $customer) {
            return 0;
        }

        return $this->visibleFor($customer)
            ->when($customer->notifications_read_at, fn ($q) => $q->where('sent_at', '>', $customer->notifications_read_at))
            ->count();
    }

    public function markRead(Customer $customer): void
    {
        $customer->forceFill(['notifications_read_at' => now()])->save();
    }

    public function autoEnabled(string $key): bool
    {
        // notify_new_arrivals / notify_preorders — default on.
        return (bool) Setting::get($key, true);
    }
}
