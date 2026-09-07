<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\PointTransaction;
use App\Services\LoyaltyService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The referral program: every member has an invite link; a guest who arrives
 * on it carries the inviter in an encrypted first-party cookie; when that
 * guest becomes a member (register, claim, Google) the link is attributed;
 * and the first delivered order pays both sides (LoyaltyService).
 *
 * The cookie is server-written and server-read, so it stays encrypted and
 * httpOnly — it is a token that grants points, not something a script needs.
 */
class Referral
{
    public const COOKIE = 'ref';

    public const DAYS = 60;

    /** Same-request handoff for a cookie that has only been queued. */
    public const ATTRIBUTE = 'referral.code';

    /** The member's invite code, minted on first use and stable after that. */
    public static function codeFor(Customer $customer): string
    {
        if (filled($customer->referral_code)) {
            return $customer->referral_code;
        }

        $base = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $customer->firstName()));
        $base = substr($base !== '' ? $base : 'NC', 0, 4);

        do {
            $code = $base.strtoupper(Str::random(5));
        } while (Customer::where('referral_code', $code)->exists());

        $customer->forceFill(['referral_code' => $code])->saveQuietly();

        return $code;
    }

    public static function inviteUrl(Customer $customer): string
    {
        return route('invite', self::codeFor($customer));
    }

    /** The member behind an invite code, or null for unknown / blocked. */
    public static function find(?string $code): ?Customer
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || strlen($code) > 20 || ! preg_match('/^[A-Z0-9]+$/', $code)) {
            return null;
        }

        return Customer::where('referral_code', $code)->where('blacklisted', false)->first();
    }

    /** Remember an invite for this browser. */
    public static function remember(Request $request, string $code): ?Customer
    {
        $referrer = self::find($code);
        if (! $referrer) {
            return null;
        }

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $referrer->referral_code,
            minutes: self::DAYS * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
        // A queued cookie is invisible until the next request; publish it for
        // anything later in THIS request (the register page's banner).
        $request->cookies->set(self::COOKIE, $referrer->referral_code);
        $request->attributes->set(self::ATTRIBUTE, $referrer->referral_code);

        return $referrer;
    }

    /** The inviter waiting in this browser's cookie, if any. */
    public static function pending(Request $request): ?Customer
    {
        $code = $request->attributes->get(self::ATTRIBUTE) ?: $request->cookie(self::COOKIE);

        return $code ? self::find((string) $code) : null;
    }

    /**
     * Attribute a customer who just became a member to the inviter in their
     * cookie. Never overwrites an existing attribution, never self-refers,
     * and consumes the cookie so a second registration in the same browser
     * cannot re-use it.
     */
    public static function attach(Customer $customer, Request $request): ?Customer
    {
        if ($customer->referred_by) {
            return null;
        }

        $referrer = self::pending($request);
        if (! $referrer || $referrer->id === $customer->id) {
            return null;
        }

        $customer->forceFill(['referred_by' => $referrer->id])->saveQuietly();
        Cookie::queue(Cookie::forget(self::COOKIE));

        try {
            app(NotificationService::class)->broadcast([
                'type' => 'referral', 'icon' => '🤝',
                'title' => $customer->firstName().' joined with your invite',
                'body' => 'You both get '.app(LoyaltyService::class)->referralPoints().' points once their first order is delivered.',
                'url' => route('account.referrals'), 'cta_label' => 'My invites',
                'recipient_ids' => [$referrer->id],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $referrer;
    }

    /** @return array{joined:int, rewarded:int, points:int} */
    public static function stats(Customer $customer): array
    {
        return [
            'joined' => $customer->referrals()->count(),
            'rewarded' => $customer->referrals()->where('referral_rewarded', true)->count(),
            'points' => (int) PointTransaction::where('customer_id', $customer->id)->where('type', 'referral_referrer')->sum('points'),
        ];
    }
}
