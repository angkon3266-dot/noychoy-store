<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use App\Support\Occasions;
use App\Support\UpcomingOccasions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Admin → Customers → Birthdays & anniversaries.
 *
 * Owner, 2026-09-17: "Create a dashboard for birthdays where I will be able to
 * see the customer's name and details for upcoming birthdays, anniversaries —
 * show it in the customers section". The dates were already being collected
 * (profile, and checkout until 2026-09-11) and crm:occasions sends its own
 * reminder and wish, but nothing showed the owner who was coming up — so there
 * was no way to pick out the customers worth a call, a personal WhatsApp or a
 * new order on their day.
 *
 * Lives under `customers.` on purpose: the section gate reads the route name,
 * so managers and admins reach it and staff (orders only) do not, and the
 * Customers entry in the sidebar stays lit while it is open.
 */
class CustomerOccasionController extends Controller
{
    /** The "when" pills, in the order they are shown. */
    public const RANGES = [
        'today' => 'Today',
        '7d' => '7 days',
        '30d' => '30 days',
        'this-month' => 'This month',
        'next-month' => 'Next month',
    ];

    public const TYPES = [
        'all' => 'All',
        'birthday' => 'Birthdays',
        'anniversary' => 'Anniversaries',
    ];

    public function index(Request $request, SmsService $sms)
    {
        $today = UpcomingOccasions::today();
        $range = $this->pick($request->query('range'), self::RANGES, '30d');
        $type = $this->pick($request->query('type'), self::TYPES, 'all');
        $q = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '';

        [$from, $to] = $this->window($range, $today);

        // The tiles are the whole picture whatever the filters say, so they
        // come from one unfiltered 30-day pass; the list reuses that pass when
        // it is asking the same question, which is every plain visit.
        $month = UpcomingOccasions::upcoming(UpcomingOccasions::DEFAULT_DAYS, now: $today);
        $rows = $range === '30d' && $type === 'all' && $q === ''
            ? $month
            : UpcomingOccasions::between($from, $to, $type === 'all' ? UpcomingOccasions::TYPES : [$type], $q, $today);

        // "2 anniversaries in October" when that is all there is, even with
        // the All pill on — "2 birthdays and anniversaries" would be wrong.
        $kinds = $rows->pluck('type')->unique();
        $counted = $type === 'all' && $kinds->count() === 1 ? $kinds->first() : $type;

        return view('admin.customers.occasions', [
            'today' => $today,
            'range' => $range,
            'type' => $type,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'ranges' => self::RANGES,
            'types' => self::TYPES,
            'groups' => $rows->groupBy(fn (array $row) => $row['date']->toDateString()),
            'total' => $rows->count(),
            'tiles' => [
                'today' => $this->tally($month->where('days_until', 0)),
                '7d' => $this->tally($month->where('days_until', '<', 7)),
                '30d' => $this->tally($month),
            ],
            'coverage' => UpcomingOccasions::coverage(),
            'noun' => $this->noun($counted, $rows->count()),
            'phrase' => $this->phrase($range, $from),
            'automation' => ['enabled' => Occasions::enabled(), 'reminder_days' => Occasions::reminderDays()],
            'smsReady' => $sms->isEnabled(),
            // The call-reminder screen was built alongside this one. Until its
            // route exists the button is simply not drawn, so this page can
            // ship first without a route() call that would 500 it — and a role
            // without that section is not handed a button that 403s.
            'canRemind' => Route::has('admin.reminders.create') && (bool) $request->user()?->canAccess('reminders'),
        ]);
    }

    /** A known key from the query string, or the default for anything else. */
    protected function pick(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && array_key_exists($value, $allowed) ? $value : $default;
    }

    /**
     * The first and last shop day a pill covers, both included.
     *
     * "This month" runs from today, not from the 1st: the page lists what is
     * coming, and a birthday that was on the 3rd comes round next year. "Next
     * month" is the whole of it, which is the one to plan gifts and stock by.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function window(string $range, CarbonImmutable $today): array
    {
        $next = $today->startOfMonth()->addMonth();

        return match ($range) {
            'today' => [$today, $today],
            '7d' => [$today, $today->addDays(6)],
            'this-month' => [$today, $today->endOfMonth()->startOfDay()],
            'next-month' => [$next, $next->endOfMonth()->startOfDay()],
            default => [$today, $today->addDays(UpcomingOccasions::DEFAULT_DAYS - 1)],
        };
    }

    /** @return array{total: int, birthday: int, anniversary: int} */
    protected function tally(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'birthday' => $rows->where('type', 'birthday')->count(),
            'anniversary' => $rows->where('type', 'anniversary')->count(),
        ];
    }

    /**
     * What the list is counting, agreeing with the number: "1 birthday",
     * "3 birthdays and anniversaries", and "No birthdays or anniversaries".
     */
    protected function noun(string $type, int $count): string
    {
        [$none, $one, $many] = match ($type) {
            'birthday' => ['birthdays', 'birthday', 'birthdays'],
            'anniversary' => ['anniversaries', 'anniversary', 'anniversaries'],
            default => ['birthdays or anniversaries', 'birthday or anniversary', 'birthdays and anniversaries'],
        };

        return match ($count) {
            0 => $none,
            1 => $one,
            default => $many,
        };
    }

    /** The window in words, to finish "No birthdays …" and "3 anniversaries …". */
    protected function phrase(string $range, CarbonImmutable $from): string
    {
        return match ($range) {
            'today' => 'today',
            '7d' => 'in the next 7 days',
            'this-month' => 'for the rest of '.$from->format('F'),
            'next-month' => 'in '.$from->format('F'),
            default => 'in the next '.UpcomingOccasions::DEFAULT_DAYS.' days',
        };
    }
}
