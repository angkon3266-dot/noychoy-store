<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What the visitor told the gift finder — who it is for, the occasion, the
 * budget. Kept in the session for everyone and on the customer record for
 * members, and read by the "Picked for you" row, the assistant's prompt and
 * the message copy, so the shop remembers instead of asking again.
 */
class GiftProfile
{
    public const KEY = 'gift_profile';

    public const RECIPIENTS = ['her' => 'For her', 'him' => 'For him', 'me' => 'For me', 'couple' => 'For a couple'];

    /** occasion key => [label, catalogue tag] */
    public const OCCASIONS = [
        'birthday' => ['Birthday', 'Birthday'],
        'anniversary' => ['Anniversary', 'Anniversary'],
        'date-night' => ['Date night', 'Date Night'],
        'just-because' => ['Just because', 'Gift'],
    ];

    /** Remember the finder's answers when a budget link carried them (?gift=1&for=her&occasion=birthday&price_max=1000). */
    public static function rememberFromRequest(Request $request): ?array
    {
        if (! $request->boolean('gift')) {
            return null;
        }

        $for = (string) $request->query('for', '');
        $occasion = (string) $request->query('occasion', '');
        $profile = array_filter([
            'for' => array_key_exists($for, self::RECIPIENTS) ? $for : null,
            'occasion' => array_key_exists($occasion, self::OCCASIONS) ? $occasion : null,
            'min' => is_numeric($request->query('price_min')) ? (int) $request->query('price_min') : null,
            'max' => is_numeric($request->query('price_max')) ? (int) $request->query('price_max') : null,
        ], fn ($v) => $v !== null);

        if ($profile === []) {
            return null;
        }
        $profile['at'] = now()->toDateString();

        session([self::KEY => $profile]);
        if ($customer = auth('customer')->user()) {
            $customer->forceFill(['gift_profile' => $profile])->saveQuietly();
        }

        return $profile;
    }

    /** The current visitor's profile: this session first, then the member record. */
    public static function current(): ?array
    {
        $profile = session(self::KEY);
        if (! $profile && ($customer = auth('customer')->user())) {
            $profile = $customer->gift_profile;
        }

        return is_array($profile) && $profile !== [] ? $profile : null;
    }

    /** The catalogue tag that matches the occasion, if any. */
    public static function occasionTag(?array $profile): ?string
    {
        return self::OCCASIONS[$profile['occasion'] ?? ''][1] ?? null;
    }

    /** "for her, a birthday, under ৳1,000" — for prompts and message copy. */
    public static function describe(?array $profile): string
    {
        if (! $profile) {
            return '';
        }
        $parts = [];
        if (isset($profile['for'])) {
            $parts[] = strtolower(self::RECIPIENTS[$profile['for']]);
        }
        if (isset($profile['occasion'])) {
            $parts[] = strtolower(self::OCCASIONS[$profile['occasion']][0]);
        }
        if (isset($profile['max']) && ! isset($profile['min'])) {
            $parts[] = 'under '.money($profile['max']);
        } elseif (isset($profile['min']) && isset($profile['max'])) {
            $parts[] = money($profile['min']).' – '.money($profile['max']);
        } elseif (isset($profile['min'])) {
            $parts[] = money($profile['min']).' and up';
        }

        return implode(', ', $parts);
    }
}
