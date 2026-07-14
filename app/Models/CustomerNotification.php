<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerNotification extends Model
{
    protected $fillable = [
        'type', 'title', 'body', 'url', 'cta_label', 'icon', 'audience',
        'scheduled_at', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

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
