<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Version snapshot taken before a config change. previous_values / new_values
 * are encrypted JSON blobs — decrypt via the accessors, which never throw.
 */
class ConfigVersion extends Model
{
    protected $fillable = [
        'user_id', 'user_name', 'ip', 'section', 'previous_values', 'new_values', 'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function previousValues(): array
    {
        return $this->decode($this->previous_values);
    }

    public function newValues(): array
    {
        return $this->decode($this->new_values);
    }

    private function decode(?string $blob): array
    {
        if (! $blob) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($blob), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        return $q->when($term, fn ($w) => $w->where(fn ($x) => $x
            ->where('user_name', 'like', "%{$term}%")
            ->orWhere('notes', 'like', "%{$term}%")
            ->orWhere('section', 'like', "%{$term}%")));
    }
}
