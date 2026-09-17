<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A call to make later: a number, what they were asking about, and when.
 *
 * The owner, 2026-09-17: "create a reminder option where I can add customer
 * lead phone number along with items to call later". She chose a due list, an
 * alert in the notification bell when one comes due, and on each reminder a
 * Call, a WhatsApp and a "Create order" that opens the manual order form with
 * the number and the items already in it.
 *
 * Nothing here is scheduled or sent. A reminder is due because its time has
 * passed, read live — like the bell's other alerts, which is what lets snoozing
 * or ticking one off take it out of the list and the badge at once.
 */
class CallReminder extends Model
{
    protected $fillable = [
        'phone', 'name', 'customer_id', 'abandoned_cart_id', 'items', 'notes',
        'due_at', 'done_at', 'outcome', 'created_by', 'done_by', 'order_id',
    ];

    protected $casts = [
        'items' => 'array',
        'due_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    /**
     * How long a reminder counts as just due before the list calls it overdue.
     *
     * A call set for 7 PM and made at 7:20 is on time, and painting every row
     * red the minute its time passes would make "overdue" mean nothing. An hour
     * late is worth saying out loud.
     */
    public const GRACE_MINUTES = 60;

    /**
     * Snooze choices, as the list offers them. Worked out in the shop's own
     * clock (Asia/Dhaka), whatever the server's is.
     */
    public const SNOOZES = [
        'hour' => '+1 hour',
        'evening' => 'This evening, 7 PM',
        'tomorrow' => 'Tomorrow, 11 AM',
    ];

    /** The shop's call-back hours: "this evening" and "tomorrow morning". */
    public const EVENING_HOUR = 19;

    public const MORNING_HOUR = 11;

    /**
     * Stored canonically — "+880 1712-345678" and "01712345678" are one
     * number — so a reminder finds its customer, and search finds a reminder,
     * however the number was typed or pasted.
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = bd_phone((string) $value);
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function abandonedCart(): BelongsTo
    {
        return $this->belongsTo(AbandonedCart::class);
    }

    /** The sale "Create order" on this reminder became. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who ticked it off. */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /** Still to call, due or not. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /** Still to call, and its time has come. What the badge and the bell count. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('done_at')->where('due_at', '<=', now());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('done_at')->where('due_at', '>', now());
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->whereNotNull('done_at');
    }

    /**
     * By name — theirs, or the matched customer's — or by any part of the
     * number, however it was typed. Digits beside a name ("Rafi 017") are a
     * name, as on the order form's customer search.
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        $digits = preg_match('/^[\d\s()+\-.]+$/', $term) ? bd_phone($term) : '';

        return $query->where(function (Builder $w) use ($term, $digits) {
            $w->where('name', 'like', '%'.$term.'%')
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', '%'.$term.'%'));

            if ($digits !== '') {
                $w->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * The sidebar badge: calls due now.
     *
     * The layout renders this on every admin page, so a table missing after a
     * half-finished deploy costs the badge and not the whole admin — the same
     * rule AdminAlerts keeps for each of its sources.
     */
    public static function dueCount(): int
    {
        try {
            return static::due()->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isDue(): bool
    {
        return ! $this->isDone() && $this->due_at !== null && $this->due_at->lte(now());
    }

    public function isOverdue(): bool
    {
        return $this->isDue() && $this->due_at->lt(now()->subMinutes(self::GRACE_MINUTES));
    }

    /** Which tab of the list it sits under. */
    public function tab(): string
    {
        return $this->isDone() ? 'done' : ($this->isDue() ? 'due' : 'upcoming');
    }

    /** "3 hours", "2 days" — how far past its time an overdue call is. */
    public function lateBy(): string
    {
        return $this->due_at->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE, false, 1);
    }

    // ── Words ────────────────────────────────────────────────────────────────

    /** The name she typed, else the matched customer's; blank when neither. */
    public function displayName(): string
    {
        return trim((string) ($this->name ?: $this->customer?->name));
    }

    public function firstName(): string
    {
        return Str::before($this->displayName(), ' ');
    }

    /**
     * When it is due, in the shop's clock: "Today 4:30 PM", "Tomorrow 11:00 AM",
     * "In 3 days". The exact moment sits beside it (dueExact) wherever a
     * relative day alone would leave the time of the call out.
     */
    public function dueLabel(): string
    {
        $now = store_time(now());
        $due = store_time($this->due_at);
        $days = (int) round($now->copy()->startOfDay()->diffInDays($due->copy()->startOfDay(), false));
        $time = $due->format('g:i A');

        return match (true) {
            $days === 0 => 'Today '.$time,
            $days === 1 => 'Tomorrow '.$time,
            $days === -1 => 'Yesterday '.$time,
            $days > 1 => 'In '.$days.' days',
            default => abs($days).' days ago',
        };
    }

    /** "Thu 17 Sep, 4:30 PM" — with the year only when it is not this one. */
    public function dueExact(): string
    {
        return self::exactTime($this->due_at);
    }

    public static function exactTime(?Carbon $at): string
    {
        if (! $at) {
            return '';
        }

        $local = store_time($at);

        return $local->format($local->isSameYear(store_time(now())) ? 'D j M, g:i A' : 'D j M Y, g:i A');
    }

    /**
     * "Pearl Drop Necklace × 1, Opal Band (Size: 8) × 2" — the snapshot, not a
     * catalogue read. Option labels are looked up by the caller, once for a
     * whole page, and passed in by variant id.
     *
     * @param  array<int, string>  $variantLabels
     */
    public function itemSummary(array $variantLabels = []): string
    {
        return collect($this->items ?? [])
            ->map(function ($item) use ($variantLabels) {
                $label = $variantLabels[$item['variant_id'] ?? 0] ?? null;

                return trim(($item['name'] ?? 'Item').($label ? ' ('.$label.')' : '')).' × '.max(1, (int) ($item['qty'] ?? 1));
            })
            ->implode(', ');
    }

    /**
     * The WhatsApp opener: who is writing, and the pieces they asked about.
     *
     * In Bangla for a customer whose language is Bangla, in English for one
     * whose language is English, and both — Bangla first — when the shop does
     * not know, which is every lead who has never ordered. Short on purpose:
     * it asks when to talk, and the conversation does the rest.
     */
    public function whatsappMessage(): string
    {
        $name = $this->firstName();
        $store = store_name();
        $pieces = collect($this->items ?? [])->pluck('name')->filter()->unique()->values();
        $items = $pieces->take(3)->implode(', ').($pieces->count() > 3 ? ' +'.($pieces->count() - 3) : '');

        $bangla = 'হ্যালো'.($name !== '' ? ' '.$name : '').', '.$store.' থেকে লিখছি। '
            .($items !== ''
                ? 'আপনি '.$items.' নিয়ে জানতে চেয়েছিলেন — কখন কথা বলা সুবিধা হবে?'
                : 'কথামতো যোগাযোগ করছি — কখন কথা বলা সুবিধা হবে?');

        $english = 'Hello'.($name !== '' ? ' '.$name : '').', this is '.$store.'. '
            .($items !== ''
                ? 'You asked about '.$items.' — when would be a good time to talk?'
                : 'Following up as promised — when would be a good time to talk?');

        return match ($this->customer?->locale) {
            'bn' => $bangla,
            'en' => $english,
            default => $bangla."\n\n".$english,
        };
    }

    public function whatsappLink(): ?string
    {
        return wa_link($this->phone, $this->whatsappMessage());
    }

    // ── Changes ──────────────────────────────────────────────────────────────

    /**
     * When a snooze choice lands, in UTC for storage.
     *
     * "This evening" once 7 PM has gone is tomorrow evening rather than a time
     * already past, which would only put the call straight back in Due now.
     * Converted back to the app's timezone before it is returned: Eloquent
     * writes a Carbon's wall-clock time as it stands, so a Dhaka 7 PM saved
     * unconverted would be read back as 7 PM UTC — six hours late.
     */
    public static function snoozeTarget(string $choice, ?Carbon $now = null): ?Carbon
    {
        $now = ($now ?? now())->copy()->startOfMinute();
        $local = store_time($now);

        $at = match ($choice) {
            'hour' => $now->copy()->addHour(),
            'evening' => self::eveningStillAhead($now)
                ? $local->copy()->setTime(self::EVENING_HOUR, 0)
                : $local->copy()->addDay()->setTime(self::EVENING_HOUR, 0),
            'tomorrow' => $local->copy()->addDay()->setTime(self::MORNING_HOUR, 0),
            default => null,
        };

        return $at?->setTimezone(config('app.timezone'));
    }

    /** Whether "this evening, 7 PM" is still ahead in Dhaka. */
    public static function eveningStillAhead(?Carbon $now = null): bool
    {
        return store_time($now ?? now())->hour < self::EVENING_HOUR;
    }

    public function markDone(?string $outcome, ?int $userId): void
    {
        $outcome = trim((string) $outcome);

        $this->forceFill([
            'done_at' => now(),
            'done_by' => $userId,
            'outcome' => $outcome !== '' ? Str::limit($outcome, 500, '') : null,
        ])->save();
    }

    /**
     * The call became a sale: tick it off and say which order it became.
     *
     * One already ticked off by hand keeps what was written about it and only
     * learns its order. True when this is what closed it.
     */
    public function closeWithOrder(Order $order, ?int $userId): bool
    {
        if ($this->isDone()) {
            if (! $this->order_id) {
                $this->forceFill(['order_id' => $order->id])->save();
            }

            return false;
        }

        $this->forceFill([
            'done_at' => now(),
            'done_by' => $userId,
            'order_id' => $order->id,
            'outcome' => 'Order '.$order->order_number.' created',
        ])->save();

        return true;
    }
}
