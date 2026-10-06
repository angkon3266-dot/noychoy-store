<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The window the dashboard is reporting on.
 *
 * One object rather than an `int $days` passed around, because the presets are
 * not all "the last N days": "Today" and "Last month" have a hard end as well
 * as a start, and "All time" has neither. Anything taking only a day count
 * silently reports the wrong thing for three of the eight presets.
 *
 * Also carries the comparison window, so "vs previous period" means the same
 * length immediately before — whatever the caller picked.
 *
 * Days are the shop's days (config store.timezone, Asia/Dhaka), not UTC's: the
 * app stores and compares in UTC, so "Today" used to run from 06:00 to 05:59
 * Dhaka time and a 2 a.m. order landed on the day before. The bounds are held
 * in the shop's timezone and handed to queries as UTC (constrain()), and
 * per-day charts group by localDate().
 */
class DateRange
{
    /** Preset key => label, in the order the dashboard shows them. */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
        'all' => 'Maximum',
    ];

    public const DEFAULT = '30d';

    protected function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?Carbon $start,
        public readonly ?Carbon $end,
    ) {}

    /**
     * Build from the dashboard's query string. Anything unrecognised falls back
     * to the default rather than erroring — a stale bookmark or a hand-edited
     * URL should still render a dashboard.
     */
    public static function fromRequest(Request $request, string $default = self::DEFAULT): self
    {
        $default = isset(self::PRESETS[$default]) ? $default : self::DEFAULT;
        $period = (string) $request->query('period', $default);

        if ($period === 'custom') {
            return self::custom($request->query('from'), $request->query('to'));
        }

        return self::preset(isset(self::PRESETS[$period]) ? $period : $default);
    }

    /** The timezone the shop's calendar days are counted in. */
    public static function timezone(): string
    {
        return config('store.timezone') ?: config('app.timezone');
    }

    /**
     * SQL for a timestamp column's calendar date in the shop's timezone — for
     * grouping per-day charts the same way the window itself is cut.
     */
    public static function localDate(string $column = 'created_at'): string
    {
        $minutes = (int) now(self::timezone())->utcOffset();

        if ($minutes === 0) {
            return "DATE({$column})";
        }

        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column}, '".sprintf('%+d', $minutes)." minutes')"
            : "DATE(DATE_ADD({$column}, INTERVAL {$minutes} MINUTE))";
    }

    public static function preset(string $key): self
    {
        $now = now(self::timezone());

        [$start, $end] = match ($key) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'all' => [null, null],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        return new self($key, self::PRESETS[$key] ?? self::PRESETS[self::DEFAULT], $start, $end);
    }

    /**
     * An arbitrary span. Dates arrive from two <input type="date"> fields, so
     * they are parsed defensively and swapped if the admin picks them the wrong
     * way round rather than returning an empty dashboard.
     */
    public static function custom(mixed $from, mixed $to): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if (! $start && ! $end) {
            return self::preset(self::DEFAULT);
        }

        $start = ($start ?? $end)->copy()->startOfDay();
        $end = ($end ?? $start)->copy()->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return new self(
            'custom',
            $start->format('j M Y').' – '.$end->format('j M Y'),
            $start,
            $end,
        );
    }

    protected static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, self::timezone());
        } catch (\Throwable) {
            return null;
        }
    }

    public function isAllTime(): bool
    {
        return $this->start === null;
    }

    public function isCustom(): bool
    {
        return $this->key === 'custom';
    }

    /** Whole days covered, or null for all time. Used for per-day averages. */
    public function days(): ?int
    {
        if ($this->isAllTime()) {
            return null;
        }

        return max(1, (int) $this->start->diffInDays($this->end) + 1);
    }

    /**
     * Restrict a query to this window.
     *
     * The single place the bounds are applied, so "all time" means "add no
     * condition" everywhere rather than being re-derived at each call site.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function constrain($query, string $column = 'created_at')
    {
        if ($this->isAllTime()) {
            return $query;
        }

        // A Carbon is bound as its wall-clock time in its own timezone, and the
        // column holds UTC.
        return $query->whereBetween($column, [$this->start->copy()->utc(), $this->end->copy()->utc()]);
    }

    /**
     * The equivalent window immediately before this one, for "vs previous".
     * All time has nothing to compare against.
     */
    public function previous(): ?self
    {
        if ($this->isAllTime()) {
            return null;
        }

        $length = $this->start->diffInSeconds($this->end);
        $end = $this->start->copy()->subSecond();

        return new self(
            $this->key.'.prev',
            'previous period',
            $end->copy()->subSeconds($length),
            $end,
        );
    }

    /** Stable, filesystem-safe cache-key fragment for this window. */
    public function cacheKey(): string
    {
        if ($this->isAllTime()) {
            return 'all';
        }

        return $this->key === 'custom'
            ? 'c'.$this->start->format('Ymd').'-'.$this->end->format('Ymd')
            : $this->key;
    }

    /**
     * How volatile the numbers are, as a cache TTL. A window that includes
     * today keeps changing; a closed one in the past never will.
     */
    public function cacheSeconds(): int
    {
        return $this->end === null || $this->end->isFuture() || $this->end->isToday()
            ? 300
            : 3600;
    }

    /** Query-string parameters that reproduce this range. */
    public function queryParams(): array
    {
        return $this->isCustom()
            ? ['period' => 'custom', 'from' => $this->start->toDateString(), 'to' => $this->end->toDateString()]
            : ['period' => $this->key];
    }
}
