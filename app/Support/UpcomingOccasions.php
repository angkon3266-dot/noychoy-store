<?php

namespace App\Support;

use App\Console\Commands\RunOccasions;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Whose birthday or anniversary is coming up, and on which day.
 *
 * Owner, 2026-09-17: "Create a dashboard for birthdays where I will be able to
 * see the customer's name and details for upcoming birthdays, anniversaries —
 * show it in the customers section". crm:occasions already texts people on
 * its own; this is the list behind it, so the owner can see who is coming up
 * and ring, WhatsApp or sell to the ones worth a personal touch.
 *
 * Only a day and a month are stored — nobody types a birth year at a jewellery
 * checkout — so "upcoming" is worked out here: the next time that day comes
 * round, counted from the shop's own today in Dhaka. The server's clock is
 * UTC, where a birthday that began at midnight in Dhaka is still "yesterday"
 * until six in the morning; every date in this class is a Dhaka day.
 */
class UpcomingOccasions
{
    /** In the order a customer with both on the same day is listed. */
    public const TYPES = ['birthday', 'anniversary'];

    public const DEFAULT_DAYS = 30;

    /**
     * The longest window worked out at once. Any stored date comes round at
     * most once in 365 consecutive days (a leap day's gap is 365 or 366), so a
     * row never has to stand for two occurrences.
     */
    public const MAX_DAYS = 365;

    /** Midnight at the start of the shop's today, on the shop's clock. */
    public static function today(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance(store_time($now ? Carbon::instance($now) : now()))->startOfDay();
    }

    /**
     * The next $days days, today included: 30 is today and the 29 after it,
     * the same count a "last 30 days" report uses.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, array{customer: Customer, type: string, date: CarbonImmutable, days_until: int}>
     */
    public static function upcoming(int $days = self::DEFAULT_DAYS, array $types = self::TYPES, ?string $search = null, ?CarbonInterface $now = null): Collection
    {
        $today = static::today($now);
        $days = max(1, min(self::MAX_DAYS, $days));

        return static::between($today, $today->addDays($days - 1), $types, $search, $today);
    }

    /**
     * Every occasion celebrated on a shop day from $from to $to, both ends
     * included, soonest first and then by name.
     *
     * A window that starts before today is started today — this is a list of
     * what is coming, and a date that has passed comes next year instead.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, array{customer: Customer, type: string, date: CarbonImmutable, days_until: int}>
     */
    public static function between(CarbonInterface $from, CarbonInterface $to, array $types = self::TYPES, ?string $search = null, ?CarbonInterface $now = null): Collection
    {
        $today = static::today($now);
        $from = static::shopDay($from)->max($today);
        $to = static::shopDay($to)->min($from->addDays(self::MAX_DAYS - 1));

        if ($to->lt($from)) {
            return collect();
        }

        $rows = [];
        foreach (array_intersect(self::TYPES, $types) as $type) {
            foreach (static::candidates($type, $from, $to, $search)->get() as $customer) {
                $date = static::nextOccurrence((int) $customer->{$type.'_month'}, (int) $customer->{$type.'_day'}, $from);

                // The query already did the narrowing; this is the exact word
                // on the day, and the backstop if the two ever disagree.
                if ($date === null || $date->gt($to)) {
                    continue;
                }

                $rows[] = [
                    'customer' => $customer,
                    'type' => $type,
                    'date' => $date,
                    'days_until' => (int) round($today->diffInDays($date)),
                ];
            }
        }

        return collect($rows)
            ->sort(fn (array $a, array $b) => static::sortKey($a) <=> static::sortKey($b))
            ->values();
    }

    /**
     * The customers whose day falls inside the window, narrowed in SQL rather
     * than by loading the whole customer list into PHP.
     *
     * Day and month are folded into one number — 17 September is 917 — so a
     * window is a plain range, and a window that runs over New Year is the two
     * ends of the year: 20 December to 18 January is ">= 1220 OR <= 118".
     *
     * A window ending on the last day of a month also takes the days after it
     * that the month does not have that year. Those rows are real: 29 February
     * is a birthday three years in four, and checkout accepts "31 April". Both
     * are kept on the month's last day (see nextOccurrence()), so they belong
     * to a window that holds that last day. The numbers inside a range need no
     * such care — 220 to 310 already contains 229, 230 and 231.
     *
     * Blacklisted customers are deliberately NOT left out: the automation
     * skips them, but the owner still wants to see who they are.
     */
    public static function candidates(string $type, CarbonInterface $from, CarbonInterface $to, ?string $search = null): Builder
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown occasion [{$type}].");
        }

        $from = static::shopDay($from);
        $to = static::shopDay($to);
        $query = Customer::query();
        $grammar = $query->getQuery()->getGrammar();
        $monthDay = '('.$grammar->wrap($type.'_month').' * 100 + '.$grammar->wrap($type.'_day').')';

        $low = $from->month * 100 + $from->day;
        $high = $to->month * 100 + ($to->day === $to->daysInMonth ? 31 : $to->day);

        return $query
            // "> 0" also rules out NULL: half a date is no date (see hasOccasion()).
            ->where($type.'_day', '>', 0)
            ->where($type.'_month', '>', 0)
            ->where(fn (Builder $q) => $to->year > $from->year
                ? $q->whereRaw("{$monthDay} >= ?", [$low])->orWhereRaw("{$monthDay} <= ?", [$high])
                : $q->whereRaw("{$monthDay} between ? and ?", [$low, $high]))
            ->when(filled($search), fn (Builder $q) => static::search($q, trim((string) $search)));
    }

    /**
     * Name, or phone however it was typed: "01711-195772", "+880 1711 195772"
     * and "195772" all find 01711195772, which is how every phone is stored.
     * Only a term that looks like a number is read as one — "Rima 2" would
     * otherwise match every phone with a 2 in it.
     */
    protected static function search(Builder $query, string $term): void
    {
        $digits = preg_match('/^[\d\s()+\-]{3,}$/', $term) ? bd_phone($term) : '';

        $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', '%'.$term.'%');

            if ($digits !== '') {
                $q->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * The first day on or after $from that a stored day and month are kept.
     *
     * A day the month does not have that year moves back to the month's last
     * day: 29 February is celebrated on 28 February outside a leap year (the
     * usual custom, and what most people born on a leap day do themselves),
     * and a "31 April" typed at checkout lands on 30 April instead of rolling
     * into May. Null for a month or day that cannot be a date at all.
     */
    public static function nextOccurrence(int $month, int $day, CarbonInterface $from): ?CarbonImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        $from = static::shopDay($from);
        $date = static::celebratedIn($from->year, $month, $day);

        return $date->lt($from) ? static::celebratedIn($from->year + 1, $month, $day) : $date;
    }

    /** The day a stored day and month fall on in one year, on the shop's clock. */
    public static function celebratedIn(int $year, int $month, int $day): CarbonImmutable
    {
        $timezone = config('store.timezone', 'Asia/Dhaka');
        $last = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone)->daysInMonth;

        return CarbonImmutable::create($year, $month, min($day, $last), 0, 0, 0, $timezone);
    }

    /**
     * How many customers have each date on file, in one pass over the table —
     * the number that says whether an empty list means a quiet month or a
     * shop that has not collected the dates yet.
     *
     * @return array{customers: int, birthday: int, anniversary: int}
     */
    public static function coverage(): array
    {
        $query = Customer::query()->toBase()->selectRaw('count(*) as customers');

        foreach (self::TYPES as $type) {
            $query->selectRaw("sum(case when {$type}_day > 0 and {$type}_month > 0 then 1 else 0 end) as {$type}");
        }

        $row = $query->first();

        return [
            'customers' => (int) ($row->customers ?? 0),
            'birthday' => (int) ($row->birthday ?? 0),
            'anniversary' => (int) ($row->anniversary ?? 0),
        ];
    }

    /**
     * When crm:occasions already sent the reminder or the wish for the
     * occurrence on $date, or null when it has not.
     *
     * The automation keeps no message log, only one stamp per customer per
     * kind, and it re-arms after RunOccasions::COOLDOWN_DAYS rather than on
     * 1 January. So a stamp belongs to this occurrence when it is newer than
     * that cooldown counted back from the day itself: this year's reminder
     * (days before) and wish (on the day) always are, and last year's wish —
     * a whole year before the day — never is. RunOccasions stamps before it
     * queues, and SendOccasionMessage clears the stamp when nothing went out,
     * so a stamp that is still there means a message did.
     */
    public static function sentAt(Customer $customer, string $type, string $kind, CarbonInterface $date): ?CarbonInterface
    {
        $at = $customer->{RunOccasions::stampColumn($type, $kind)};

        return $at && $at->gte(CarbonImmutable::instance($date)->subDays(RunOccasions::COOLDOWN_DAYS)) ? $at : null;
    }

    /** "Today", "Tomorrow", then "in 12 days". */
    public static function whenLabel(int $daysUntil): string
    {
        return match (true) {
            $daysUntil <= 0 => 'Today',
            $daysUntil === 1 => 'Tomorrow',
            default => "in {$daysUntil} days",
        };
    }

    /** A day's heading: "Today", "Tomorrow", "Sat 19 Sep" — with the year once it is not this one. */
    public static function dayLabel(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        $today = static::today($now);
        $date = static::shopDay($date);

        return match (true) {
            $date->eq($today) => 'Today',
            $date->eq($today->addDay()) => 'Tomorrow',
            $date->year !== $today->year => $date->format('D j M Y'),
            default => $date->format('D j M'),
        };
    }

    /**
     * A greeting for the owner to send personally, in Bangla, naming the day
     * and the shop. It says "অগ্রিম" (in advance) until the day itself, so a
     * message sent on Tuesday for a Friday birthday does not claim it is Friday.
     *
     * $sms is the short cut: Bangla goes out as unicode, 70 characters to a
     * paid part, so it keeps to one part for most names and carries no emoji
     * (two characters each, and blank boxes on a feature phone). WhatsApp is
     * free, so that one says a little more.
     */
    public static function wish(Customer $customer, string $type, int $daysUntil, bool $sms = false): string
    {
        $name = static::greetingName($customer);
        $early = $daysUntil > 0 ? 'অগ্রিম ' : '';
        $store = store_name();
        $birthday = $type === 'birthday';

        $opening = $early.'শুভ '.Occasions::labelBn($type).($name !== '' ? ', '.$name : '').'!';

        if ($sms) {
            return $opening.' ভালোবাসা ও শুভেচ্ছা রইল — '.$store;
        }

        return $opening.' '.($birthday ? '🎂' : '💍')."\n\n"
            .$store.' এর পক্ষ থেকে '.($birthday ? 'আপনার' : 'আপনাদের দুজনের').' জন্য রইল অনেক অনেক ভালোবাসা ও শুভেচ্ছা। '
            .($birthday ? 'আপনার দিনটা আনন্দে ভরে উঠুক।' : 'একসাথে পথচলা আরও সুন্দর হোক।');
    }

    /**
     * The name a greeting uses: the first one, passing over a leading "Md." or
     * "Mohammad" — "Happy birthday, Md.!" is what Customer::firstName() would
     * write for a great many Bangladeshi customers.
     */
    public static function greetingName(Customer $customer): string
    {
        $parts = preg_split('/\s+/u', trim((string) $customer->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        while (count($parts) > 1 && preg_match('/^(md|mst|mohd|mohammad|mohammed|muhammad|mr|mrs|ms|dr|মোঃ|মো:|মোছাঃ|মোসাঃ)\.?$/iu', $parts[0])) {
            array_shift($parts);
        }

        return $parts[0] ?? '';
    }

    /** Any date or time, as the shop day it falls on. */
    protected static function shopDay(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config('store.timezone', 'Asia/Dhaka'))->startOfDay();
    }

    /** Soonest first, then name, then birthday before anniversary, then id. */
    protected static function sortKey(array $row): array
    {
        return [
            $row['date']->getTimestamp(),
            mb_strtolower(trim((string) $row['customer']->name)),
            array_search($row['type'], self::TYPES, true),
            $row['customer']->id,
        ];
    }
}
