<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A wishlist someone chose to share by link (Nov 2026). */
class WishlistShare extends Model
{
    protected $fillable = ['code', 'visitor_token', 'customer_id', 'owner_name'];

    public static function newCode(): string
    {
        // No look-alike characters: people read these out on the phone. Drawn
        // with random_int (a CSPRNG) — str_shuffle runs on mt_rand, whose
        // output can be predicted, and a guessed code shows someone's list.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** The loves this share stands for, newest first: the member's and this browser's. */
    public function productIds(): array
    {
        return ProductLove::query()
            ->where(function ($q) {
                if ($this->customer_id) {
                    $q->orWhere('customer_id', $this->customer_id);
                }
                if ($this->visitor_token) {
                    $q->orWhere('visitor_token', $this->visitor_token);
                }
            })
            ->when(! $this->customer_id && ! $this->visitor_token, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('id')->pluck('product_id')->unique()->values()->all();
    }

    public function getFirstNameAttribute(): ?string
    {
        return $this->owner_name ? Str::of($this->owner_name)->trim()->explode(' ')->first() : null;
    }
}
