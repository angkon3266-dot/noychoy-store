<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat session with the storefront assistant. The transcript lives in
 * assistant_messages; this row is the header the admin list reads.
 */
class AssistantConversation extends Model
{
    protected $fillable = [
        'uid', 'customer_id', 'visitor_token', 'first_page', 'last_page', 'ua',
        'message_count', 'had_failure', 'last_message_at',
    ];

    protected $casts = [
        'message_count' => 'integer',
        'had_failure' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The question that opened the conversation — the list's preview line. */
    public function getOpenerAttribute(): string
    {
        return (string) ($this->messages()->where('role', 'user')->orderBy('id')->value('content') ?? '');
    }

    /** "Ayesha" for a member, "Guest" otherwise. */
    public function getWhoAttribute(): string
    {
        return $this->customer?->name ?: 'Guest';
    }
}
