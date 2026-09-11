<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMessage extends Model
{
    /** Appended once and never edited, so there is no updated_at to keep. */
    public const UPDATED_AT = null;

    protected $fillable = ['assistant_conversation_id', 'role', 'content', 'products', 'ok', 'created_at'];

    protected $casts = [
        'products' => 'array',
        'ok' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
