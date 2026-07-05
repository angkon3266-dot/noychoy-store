<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * A named, restorable snapshot of the entire configuration store (encrypted).
 */
class ConfigBackup extends Model
{
    protected $fillable = [
        'name', 'user_id', 'creator_name', 'payload', 'size_bytes', 'modules', 'is_auto',
    ];

    protected $casts = [
        'modules' => 'array',
        'is_auto' => 'boolean',
        'size_bytes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Decrypt the stored config payload. Returns [] if unreadable. */
    public function decodedPayload(): array
    {
        try {
            return json_decode(Crypt::decryptString($this->payload), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
    }

    public function humanSize(): string
    {
        $b = $this->size_bytes;

        return $b >= 1048576 ? round($b / 1048576, 2).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
    }
}
