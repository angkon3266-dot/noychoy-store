<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single follow-up attempt on an abandoned cart — a call, a WhatsApp
 * message, a recovery SMS, or a note somebody left for whoever picks the
 * lead up next.
 */
class AbandonedCartContact extends Model
{
    /** Channels a lead can be chased on. Keys are stored, values shown. */
    public const CHANNELS = [
        'call' => 'Phone call',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'note' => 'Note',
    ];

    /** How the attempt went. Blank is allowed — sometimes there is nothing to say. */
    public const OUTCOMES = [
        'no_answer' => 'No answer',
        'will_order' => 'Will order',
        'thinking' => 'Still deciding',
        'not_interested' => 'Not interested',
        'wrong_number' => 'Wrong number',
        'ordered' => 'Ordered',
    ];

    protected $fillable = ['abandoned_cart_id', 'user_id', 'channel', 'outcome', 'note'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(AbandonedCart::class, 'abandoned_cart_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? ucfirst((string) $this->channel);
    }

    public function outcomeLabel(): ?string
    {
        return $this->outcome ? (self::OUTCOMES[$this->outcome] ?? $this->outcome) : null;
    }

    /** Tailwind pair for the channel chip, matching the neighbouring admin screens. */
    public function channelBadgeClass(): string
    {
        return match ($this->channel) {
            'call' => 'bg-blue-100 text-blue-700',
            'whatsapp' => 'bg-green-100 text-green-700',
            'sms' => 'bg-violet-100 text-violet-700',
            default => 'bg-ink-100 text-ink-700',
        };
    }
}
