<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['visitor_token', 'event', 'path', 'product_id', 'referrer_host'];

    /** Record one traffic event; never throws into the request. */
    public static function record(string $event, array $attrs = []): void
    {
        try {
            // The middleware passes the token explicitly on a visitor's first
            // request, before the cookie exists on the request object.
            $token = $attrs['visitor_token'] ?? request()->cookie('visitor_token');
            if (blank($token)) {
                return;
            }
            unset($attrs['visitor_token']);

            static::create(array_merge([
                'visitor_token' => substr((string) $token, 0, 40),
                'event' => $event,
                'path' => substr((string) request()->path(), 0, 255),
                'referrer_host' => ($ref = request()->headers->get('referer'))
                    ? substr((string) parse_url($ref, PHP_URL_HOST), 0, 120)
                    : null,
            ], $attrs));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
