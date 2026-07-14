<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerNotification extends Model
{
    protected $fillable = [
        'type', 'title', 'body', 'url', 'cta_label', 'icon', 'audience', 'segment_id',
        'recipients_count', 'clicks', 'scheduled_at', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function recipients(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_notification_recipients');
    }

    public function segment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomerSegment::class, 'segment_id');
    }

    /** Delivered notifications (sent, not future-scheduled), newest first. */
    public function scopeSent(Builder $q): Builder
    {
        return $q->whereNotNull('sent_at')->orderByDesc('sent_at');
    }

    public function iconOrDefault(): string
    {
        return $this->icon ?: match ($this->type) {
            'new_arrival' => '✨',
            'preorder' => '📅',
            'system' => '🔔',
            default => '🎁',
        };
    }
}
