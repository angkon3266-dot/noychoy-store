<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Setting;
use App\Services\CartService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * The spin-and-win wheel on the checkout page (owner, 2026-10-31).
 *
 * The owner sets the slices in Admin → Offers — what each one gives (% off,
 * ৳ off, free delivery, or nothing) and how often it comes up. The customer
 * spins once, and a prize is applied to her order there and then.
 *
 * The server decides the slice; the browser only animates to it. A prize is
 * minted as an ordinary single-use coupon and put on the cart the way a typed
 * code is, so pricing, the summary, and PlaceOrder's locked re-check all treat
 * it exactly like any other coupon — nothing about checkout learns a new rule.
 *
 * One spin per shopper: the result is kept in the session and in a cookie for
 * as long as the prize is valid, so reloading the page shows what she won
 * rather than offering another go. Clearing cookies does buy another spin —
 * nothing on a guest checkout can stop that — which is what "one prize per
 * phone number" is for: it is checked when the order is placed, against every
 * code the wheel has ever minted.
 */
class SpinWheel
{
    public const SOURCE = 'spin_wheel';

    public const TYPES = ['percent', 'flat', 'free_delivery', 'none'];

    public const MIN_SEGMENTS = 2;

    public const MAX_SEGMENTS = 12;

    protected const SESSION_KEY = 'spin_wheel';

    protected const COOKIE = 'nc_spin';

    /** Alternating slice colours when the owner leaves one blank. */
    public const PALETTE = ['#111827', '#B8892E', '#7F1D1D', '#F5EBD7', '#1E3A5F', '#D6B36A'];

    /** No O/0, no I/L/1 — read off a screen, typed with a thumb. */
    protected const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function defaults(): array
    {
        return [
            ['label' => '5% OFF', 'type' => 'percent', 'value' => 5, 'weight' => 30],
            ['label' => 'Try again', 'type' => 'none', 'value' => null, 'weight' => 20],
            ['label' => '৳50 OFF', 'type' => 'flat', 'value' => 50, 'weight' => 20],
            ['label' => 'Free delivery', 'type' => 'free_delivery', 'value' => null, 'weight' => 15],
            ['label' => '10% OFF', 'type' => 'percent', 'value' => 10, 'weight' => 10],
            ['label' => '৳100 OFF', 'type' => 'flat', 'value' => 100, 'weight' => 5],
        ];
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public function enabled(): bool
    {
        if (! (bool) Setting::get('spin_wheel_enabled', false)) {
            return false;
        }

        $segments = $this->segments();

        return count($segments) >= self::MIN_SEGMENTS
            && collect($segments)->contains(fn ($s) => $s['type'] !== 'none');
    }

    /** @return array<int, array{label:string, type:string, value:?float, weight:int, color:string}> */
    public function segments(): array
    {
        return static::normalise(Setting::get('spin_wheel_segments', static::defaults()));
    }

    public function title(): string
    {
        return (string) (Setting::get('spin_wheel_title') ?: 'Spin & win');
    }

    public function subtitle(): string
    {
        return (string) (Setting::get('spin_wheel_subtitle') ?: 'One free spin — whatever you win comes off this order.');
    }

    /** How long a prize lasts, and how long one spin holds the wheel. */
    public function validHours(): int
    {
        return max(1, min(720, (int) Setting::get('spin_wheel_valid_hours', 24)));
    }

    /** The order value (after offers) the wheel waits for, and the prize needs. */
    public function minCart(): float
    {
        return max(0, (float) Setting::get('spin_wheel_min_cart', 0));
    }

    public static function oncePerPhone(): bool
    {
        return (bool) Setting::get('spin_wheel_once_per_phone', true);
    }

    /** Has an order on this number ever been placed with a wheel prize? */
    public static function phoneHasUsedAPrize(?string $phone): bool
    {
        if (blank($phone)) {
            return false;
        }

        return Order::where('customer_phone', bd_phone($phone))
            ->whereIn('coupon_code', Coupon::select('code')->where('source', self::SOURCE))
            ->exists();
    }

    /**
     * Sanitise slices from settings or the admin form, so what is stored is
     * exactly what the wheel will spin.
     *
     * @return array<int, array{label:string, type:string, value:?float, weight:int, color:string}>
     */
    public static function normalise(mixed $rows): array
    {
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $type = (string) ($row['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }

            $value = isset($row['value']) && is_numeric($row['value']) ? round((float) $row['value'], 2) : null;
            if (in_array($type, ['percent', 'flat'], true) && ($value === null || $value <= 0)) {
                continue;
            }
            if ($type === 'percent') {
                $value = min(90.0, $value);
            }
            if (! in_array($type, ['percent', 'flat'], true)) {
                $value = null;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = static::defaultLabel($type, $value);
            }

            $color = (string) ($row['color'] ?? '');
            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = self::PALETTE[count($out) % count(self::PALETTE)];
            }

            $out[] = [
                'label' => mb_substr($label, 0, 24),
                'type' => $type,
                'value' => $value,
                'weight' => max(0, min(1000, (int) ($row['weight'] ?? 1))),
                'color' => strtoupper($color),
            ];

            if (count($out) >= self::MAX_SEGMENTS) {
                break;
            }
        }

        return $out;
    }

    protected static function defaultLabel(string $type, ?float $value): string
    {
        $n = rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

        return match ($type) {
            'percent' => $n.'% OFF',
            'flat' => '৳'.$n.' OFF',
            'free_delivery' => 'Free delivery',
            default => 'Try again',
        };
    }

    // ── The spin ────────────────────────────────────────────────────────────

    /**
     * What the checkout page shows: the wheel, the result of a spin already
     * taken, or nothing at all.
     */
    public function forCheckout(CartService $cart): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $last = $this->lastSpin($cart);

        // She brought a code of her own: the wheel stays out of the way, since
        // a prize would replace it.
        if (! $last && $this->typedCodeOnCart($cart)) {
            return null;
        }

        return [
            'title' => $this->title(),
            'subtitle' => $this->subtitle(),
            'segments' => collect($this->segments())
                ->map(fn ($s) => ['label' => $s['label'], 'color' => $s['color'], 'prize' => $s['type'] !== 'none'])
                ->values()->all(),
            'result' => $last ? $this->present($last, $cart) : null,
            'blocked' => $last ? null : $this->blockedReason($cart),
        ];
    }

    /**
     * Spin. Returns the result the page animates to, or a refusal.
     *
     * @return array{ok:bool, message?:string, index?:int, result?:array}
     */
    public function spin(CartService $cart): array
    {
        if (! $this->enabled() || $cart->isEmpty()) {
            return ['ok' => false, 'message' => 'The wheel is not available right now.'];
        }

        if ($last = $this->lastSpin($cart)) {
            return ['ok' => false, 'message' => 'You have already spun the wheel.', 'result' => $this->present($last, $cart)];
        }

        if ($reason = $this->blockedReason($cart)) {
            return ['ok' => false, 'message' => $reason];
        }

        $segments = $this->segments();
        $index = $this->pick($segments);
        $segment = $segments[$index];

        $coupon = $segment['type'] === 'none' ? null : $this->mint($segment);

        if ($coupon) {
            $cart->applyCoupon($coupon);
        }

        $spin = [
            'i' => $index,
            'label' => $segment['label'],
            'code' => $coupon?->code,
            'at' => now()->timestamp,
        ];

        session([self::SESSION_KEY => $spin]);
        Cookie::queue(self::COOKIE, json_encode($spin), $this->validHours() * 60);

        return ['ok' => true, 'index' => $index, 'result' => $this->present($spin, $cart)];
    }

    /**
     * Why this shopper cannot spin, or null when she can.
     *
     * A typed code already on the cart stays put: a prize would replace it,
     * and a spin should never be able to lose her a better discount she
     * brought with her.
     */
    protected function blockedReason(CartService $cart): ?string
    {
        if ($code = $this->typedCodeOnCart($cart)) {
            return 'Your coupon '.$code.' is already on this order.';
        }

        // Shown on the card rather than hiding it: "add ৳300 more" is a
        // reason to add a piece.
        if (($short = $this->minCart() - $cart->couponBase()) > 0) {
            return 'Add '.money(ceil($short)).' more to unlock your free spin.';
        }

        if (static::oncePerPhone() && static::phoneHasUsedAPrize($cart->checkoutPhone())) {
            return 'This number has already used its wheel prize.';
        }

        return null;
    }

    /** A code on the cart that the wheel did not mint, if any. */
    protected function typedCodeOnCart(CartService $cart): ?string
    {
        $code = $cart->storedCouponCode();

        return $code && ! Coupon::where('code', $code)->where('source', self::SOURCE)->exists() ? $code : null;
    }

    /** Weighted draw — the weights never leave the server. */
    protected function pick(array $segments): int
    {
        $total = array_sum(array_column($segments, 'weight'));
        $roll = random_int(1, max(1, $total));

        foreach ($segments as $i => $s) {
            $roll -= $s['weight'];
            if ($s['weight'] > 0 && $roll <= 0) {
                return $i;
            }
        }

        return array_key_last($segments);
    }

    protected function mint(array $segment): Coupon
    {
        do {
            $code = 'SPIN';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Coupon::where('code', $code)->exists());

        $min = $this->minCart();

        return Coupon::create([
            'code' => $code,
            'label' => 'Spin wheel · '.$segment['label'],
            'source' => self::SOURCE,
            'type' => $segment['type'] === 'percent' ? 'percent' : 'fixed',
            'value' => $segment['type'] === 'free_delivery' ? 0 : $segment['value'],
            'free_shipping' => $segment['type'] === 'free_delivery',
            'applies_to' => 'all',
            'min_order' => $min > 0 ? $min : null,
            'usage_limit' => 1,
            'per_customer_limit' => 1,
            'is_exclusive' => false,
            'expires_at' => Carbon::now()->addHours($this->validHours()),
            'is_active' => true,
        ]);
    }

    /**
     * The spin this shopper already took, while it still holds the wheel.
     *
     * @return array{i:int, label:string, code:?string, at:int}|null
     */
    protected function lastSpin(CartService $cart): ?array
    {
        $spin = session(self::SESSION_KEY);
        $fromCookie = false;

        if (! is_array($spin)) {
            $raw = request()->cookie(self::COOKIE);
            $spin = is_string($raw) ? json_decode($raw, true) : null;
            $fromCookie = true;
        }

        if (! is_array($spin) || ! isset($spin['at'])) {
            return null;
        }

        if ((int) $spin['at'] + $this->validHours() * 3600 < now()->timestamp) {
            session()->forget(self::SESSION_KEY);

            return null;
        }

        // Back from the cookie in a new session: put the prize back on the
        // cart, as it was when she won it — unless she has a code of her own
        // there by now.
        if ($fromCookie) {
            session([self::SESSION_KEY => $spin]);

            if (($coupon = $this->prizeCoupon($spin)) && $this->stillSpendable($coupon) && ! $cart->storedCouponCode()) {
                $cart->applyCoupon($coupon);
            }
        }

        return $spin;
    }

    protected function prizeCoupon(array $spin): ?Coupon
    {
        $code = $spin['code'] ?? null;

        return $code ? Coupon::where('code', $code)->where('source', self::SOURCE)->first() : null;
    }

    protected function stillSpendable(Coupon $coupon): bool
    {
        return $coupon->is_active
            && $coupon->used_count < (int) ($coupon->usage_limit ?? 1)
            && ! $coupon->expires_at?->isPast();
    }

    /**
     * The result card: what she won, and whether it is on this order — and if
     * not, why not, in words she can act on.
     */
    protected function present(array $spin, CartService $cart): array
    {
        $coupon = $this->prizeCoupon($spin);
        $applied = $coupon !== null && $cart->coupon()?->is($coupon);

        $note = null;
        if ($coupon && ! $applied) {
            $note = match (true) {
                $coupon->used_count >= (int) ($coupon->usage_limit ?? 1) => 'Already used on an earlier order.',
                ! $this->stillSpendable($coupon) => 'This prize has expired.',
                $coupon->min_order !== null && $cart->couponBase() < (float) $coupon->min_order => 'Spend '.money((float) $coupon->min_order).' to use it.',
                $cart->storedCouponCode() !== null && $cart->storedCouponCode() !== $coupon->code => 'Your other coupon is on this order instead.',
                default => 'Not applied — the discount already on this order saves you more.',
            };
        }

        return [
            'index' => (int) ($spin['i'] ?? 0),
            'label' => (string) ($spin['label'] ?? ''),
            'won' => $coupon !== null,
            'code' => $coupon?->code,
            'applied' => $applied,
            'note' => $note,
        ];
    }
}
