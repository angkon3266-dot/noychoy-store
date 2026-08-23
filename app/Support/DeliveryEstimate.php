<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The delivery window a buyer is shown — "arrives Tue 26 – Thu 28 Aug".
 *
 * Before this class the same three lines of Carbon were pasted in two places
 * (the React product page and the legacy Blade one) and neither knew the two
 * things a Bangladeshi courier knows: inside Dhaka is faster than outside, and
 * nobody delivers on Friday. Counting plain calendar days across a Friday
 * promises a date the courier will miss — which is the one promise a
 * cash-on-delivery store cannot afford to break, because the customer has to
 * physically be there with the money.
 *
 * One builder, so the product page, the confirmation page and anything added
 * later can never quote different windows for the same order.
 */
class DeliveryEstimate
{
    protected function __construct(
        public readonly Carbon $from,
        public readonly ?Carbon $to,
    ) {}

    /**
     * @param  ?bool  $insideDhaka  true/false once the zone is known; null on a
     *                              product page, where it quotes the slower
     *                              nationwide window rather than over-promising.
     */
    public static function for(?bool $insideDhaka = null, ?Carbon $placedAt = null): ?self
    {
        if (! theme('show_delivery_estimate')) {
            return null;
        }

        [$min, $max] = $insideDhaka === true
            ? [(int) theme('delivery_days_inside_min', 1), (int) theme('delivery_days_inside_max', 2)]
            : [(int) theme('delivery_days_min', 2), (int) theme('delivery_days_max', 4)];

        $min = max(0, $min);
        $max = max($min, $max);

        // Anchored on the shop's local day, not the server's. Between
        // midnight and 6am Dhaka time UTC is still on yesterday's date, so a
        // window counted from it lands a full day early — and on cash on
        // delivery that means the customer arranged to be home, with the
        // money, on a day the courier was never coming.
        $tz = config('store.timezone', 'Asia/Dhaka');
        $start = ($placedAt ? $placedAt->copy() : now())->setTimezone($tz);
        $off = static::offDays();

        $from = static::advance($start, $min, $off);
        $to = $max > $min ? static::advance($start, $max, $off) : null;

        // A wider window that lands on the same working day is not a window.
        if ($to && $to->isSameDay($from)) {
            $to = null;
        }

        return new self($from, $to);
    }

    /** Carbon day numbers the courier skips. Never all seven. */
    protected static function offDays(): array
    {
        $raw = theme('delivery_off_days', [Carbon::FRIDAY]);

        $days = collect(is_array($raw) ? $raw : [$raw])
            ->map(fn ($d) => (int) $d)
            ->filter(fn ($d) => $d >= 0 && $d <= 6)
            ->unique()->values()->all();

        // Seven off days would spin forever; read it as "none configured".
        return count($days) >= 7 ? [] : $days;
    }

    /**
     * Add $days *delivery* days, then roll forward off any non-delivery day, so
     * the quoted date is always one the courier actually works.
     */
    protected static function advance(Carbon $start, int $days, array $off): Carbon
    {
        $d = $start->copy()->startOfDay();

        while ($days > 0) {
            $d->addDay();
            if (! in_array($d->dayOfWeek, $off, true)) {
                $days--;
            }
        }

        while (in_array($d->dayOfWeek, $off, true)) {
            $d->addDay();
        }

        return $d;
    }

    /** "Tue 26 – Thu 28 Aug", or "Tue 26 Aug" when it is a single day. */
    public function label(): string
    {
        if (! $this->to) {
            return $this->from->format('D j M');
        }

        return $this->from->isSameMonth($this->to)
            ? $this->from->format('D j').' – '.$this->to->format('D j M')
            : $this->from->format('D j M').' – '.$this->to->format('D j M');
    }

    /**
     * The exact shape the product pages already consume, so moving them onto
     * this builder changes no JSX and no Blade.
     *
     * @return array{from:string,to:?string}
     */
    public function productPageShape(): array
    {
        return [
            'from' => $this->from->format('D, d M'),
            'to' => $this->to?->format('d M'),
        ];
    }
}
