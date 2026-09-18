<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone number that may not order.
 *
 * The owner asked for this on 2026-09-18 — a way to stop a number ordering at
 * all, however it is typed. Everything is keyed on `bd_phone()`, so the same
 * person is refused whether the checkout says "01712345678", "+880 1712-345678",
 * "8801712345678", "1712345678" or "01712 345678": all five canonicalise to
 * one stored value, and the block is looked up on that.
 *
 * This is deliberately separate from `customers.blacklisted`, which is the
 * owner's "high-risk COD" marketing flag and only ever kept people out of
 * campaigns. Turning that flag into a refusal would have changed what it means
 * for everyone already carrying it; a number is blocked here only because
 * somebody said so in as many words.
 */
class BlockedPhone extends Model
{
    protected $fillable = ['phone', 'reason', 'blocked_by'];

    /** Per-request memo: the checkout asks twice on one order (page and write). */
    protected static array $memo = [];

    /** Stored canonically whatever was typed. */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = bd_phone((string) $value);
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * May this number order?
     *
     * A blank number is never blocked: the cart asks before the shopper has
     * typed anything, and refusing an unknown number would shut the checkout.
     */
    public static function blocks(?string $phone): bool
    {
        $phone = bd_phone((string) $phone);

        if ($phone === '') {
            return false;
        }

        return static::$memo[$phone] ??= static::where('phone', $phone)->exists();
    }

    /** Block a number, keeping the first reason if it is already on the list. */
    public static function block(string $phone, ?string $reason = null, ?int $userId = null): ?self
    {
        $phone = bd_phone($phone);

        if ($phone === '') {
            return null;
        }

        unset(static::$memo[$phone]);

        return static::firstOrCreate(
            ['phone' => $phone],
            ['reason' => $reason, 'blocked_by' => $userId],
        );
    }

    public static function unblock(string $phone): void
    {
        $phone = bd_phone($phone);
        unset(static::$memo[$phone]);
        static::where('phone', $phone)->delete();
    }

    /** Tests and long-running console work share one process. */
    public static function forgetMemo(): void
    {
        static::$memo = [];
    }
}
