<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = [
        'customer_id', 'audience', 'user_id', 'endpoint', 'endpoint_hash',
        'p256dh', 'auth', 'ua', 'label', 'last_used_at',
    ];

    protected $casts = ['last_used_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Shopper devices — the only ones marketing sends may ever reach. */
    public function scopeCustomers($query)
    {
        return $query->where('audience', '!=', 'admin');
    }

    /** Staff devices opted in to operational alerts (new orders). */
    public function scopeAdmins($query)
    {
        return $query->where('audience', 'admin');
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
