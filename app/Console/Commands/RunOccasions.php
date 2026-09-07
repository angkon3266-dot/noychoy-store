<?php

namespace App\Console\Commands;

use App\Jobs\SendOccasionMessage;
use App\Models\Customer;
use App\Support\Occasions;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Birthday and anniversary messages: a reminder N days before the date with
 * the matching collection, and a wish on the day (with a personal offer when
 * the owner switched one on). One daily pass.
 *
 * Stamp-before-dispatch, like the other paid-SMS automations: a customer is
 * marked the moment they are picked, and the job un-stamps only if nothing
 * went out. A 300-day cooldown instead of a "this year" flag re-arms itself
 * across timezone edges and never needs a reset.
 */
class RunOccasions extends Command
{
    protected $signature = 'crm:occasions {--dry : List who is due without sending}';

    protected $description = 'Send birthday / anniversary reminders and same-day wishes';

    public const COOLDOWN_DAYS = 300;

    public function handle(): int
    {
        if (! Occasions::enabled()) {
            $this->info('Occasion messages are switched off (Admin → Offers).');

            return self::SUCCESS;
        }

        $queued = 0;
        foreach (array_keys(Customer::OCCASIONS) as $occasion) {
            foreach (['reminder', 'wish'] as $kind) {
                $due = static::dueQuery($occasion, $kind)->orderBy('id')->limit(Occasions::perRun())->get();

                foreach ($due as $customer) {
                    $this->line(sprintf('%-11s %-8s #%d %s', $occasion, $kind, $customer->id, $customer->name));
                    if ($this->option('dry')) {
                        continue;
                    }

                    $customer->forceFill([static::stampColumn($occasion, $kind) => now()])->saveQuietly();
                    SendOccasionMessage::dispatch($customer->id, $occasion, $kind);
                    $queued++;
                }
            }
        }

        $this->info("Queued {$queued} occasion message(s).");

        return self::SUCCESS;
    }

    public static function stampColumn(string $occasion, string $kind): string
    {
        return $occasion.'_'.($kind === 'reminder' ? 'reminded_at' : 'wished_at');
    }

    /**
     * Who is due today for one occasion and kind. Dates are compared in the
     * store's own timezone — a birthday is a Dhaka day, not a UTC one.
     */
    public static function dueQuery(string $occasion, string $kind, ?CarbonInterface $today = null): Builder
    {
        $today = $today ? $today->copy() : store_time(now());
        $target = $kind === 'reminder' ? $today->copy()->addDays(Occasions::reminderDays()) : $today;
        $column = static::stampColumn($occasion, $kind);

        return Customer::query()
            ->where($occasion.'_day', $target->day)
            ->where($occasion.'_month', $target->month)
            ->where('blacklisted', false)
            // Someone we can actually reach: a phone for SMS or a login for the bell.
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('password'))
            ->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<', now()->subDays(self::COOLDOWN_DAYS)));
    }
}
