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

        return CustomerNotification::create([
            'type' => $data['type'] ?? 'announcement',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'icon' => $data['icon'] ?? null,
            'audience' => 'all',
            'scheduled_at' => $scheduled,
            'sent_at' => $scheduled ? null : now(),
            'created_by' => $data['created_by'] ?? optional(auth()->user())->id,
        ]);
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

    /** Recent delivered notifications (for the bell + dashboard). */
    public function recent(int $limit = 15)
    {
        return CustomerNotification::sent()->limit($limit)->get();
    }

    public function unreadCountFor(?Customer $customer): int
    {
        if (! $customer) {
            return 0;
        }

        return CustomerNotification::whereNotNull('sent_at')
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
