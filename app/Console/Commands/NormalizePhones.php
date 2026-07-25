<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring historical phone numbers onto the canonical 01XXXXXXXXX form that the
 * Customer/Order mutators now enforce for every new write.
 *
 * Rows written before the mutators existed can be in any format the customer
 * typed, so the same person may appear as "8801711195772", "+880 1711-195772"
 * and "1711195772". Those are the same customer and should be merged — but
 * merging deletes rows, so it only happens when explicitly asked for.
 *
 * Safe by default: reports and changes nothing until --force.
 */
class NormalizePhones extends Command
{
    protected $signature = 'phones:normalize
        {--force : Actually write the changes (otherwise this is a dry run)}
        {--merge : Also merge customers whose numbers collide once normalised}';

    protected $description = 'Normalise stored phone numbers to 01XXXXXXXXX and optionally merge the duplicates that reveals';

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $merge = (bool) $this->option('merge');

        if (! $write) {
            $this->warn('Dry run — nothing will be written. Add --force to apply.');
        }

        $this->orders($write);
        $this->customers($write, $merge);

        $this->newLine();
        $this->info($write ? 'Done.' : 'Dry run complete. Re-run with --force to apply.');

        return self::SUCCESS;
    }

    /** Orders have no uniqueness on the phone, so these are always safe. */
    protected function orders(bool $write): void
    {
        $changed = 0;

        Order::withTrashed()->whereNotNull('customer_phone')->chunkById(500, function ($orders) use (&$changed, $write) {
            foreach ($orders as $order) {
                $canonical = bd_phone($order->customer_phone);
                if ($canonical === $order->customer_phone || $canonical === '') {
                    continue;
                }
                $changed++;
                if ($write) {
                    // saveQuietly: this is a data cleanup, not a business event.
                    $order->forceFill(['customer_phone' => $canonical])->saveQuietly();
                }
            }
        });

        $this->line("Orders needing normalisation: <info>{$changed}</info>");
    }

    protected function customers(bool $write, bool $merge): void
    {
        $canonicalOf = [];      // customer id => canonical phone
        $groups = [];           // canonical phone => [customer ids]

        Customer::whereNotNull('phone')->orderBy('id')->chunk(500, function ($rows) use (&$canonicalOf, &$groups) {
            foreach ($rows as $c) {
                $canonical = bd_phone($c->phone);
                if ($canonical === '') {
                    continue;
                }
                $canonicalOf[$c->id] = $canonical;
                $groups[$canonical][] = $c->id;
            }
        });

        $needsChange = collect($canonicalOf)->filter(
            fn ($canonical, $id) => Customer::whereKey($id)->value('phone') !== $canonical
        );
        $collisions = collect($groups)->filter(fn ($ids) => count($ids) > 1);

        $this->line('Customers needing normalisation: <info>'.$needsChange->count().'</info>');
        $this->line('Numbers held by more than one customer: <info>'.$collisions->count().'</info>');

        foreach ($collisions as $phone => $ids) {
            $this->line("  {$phone} → customer ids ".implode(', ', $ids));
        }

        if ($collisions->isNotEmpty() && ! $merge) {
            $this->warn('Duplicates left untouched. Re-run with --merge to combine them (keeps the record with the most orders).');
        }

        if (! $write) {
            return;
        }

        // Merge first: it frees up the numbers the survivors need to claim.
        if ($merge) {
            foreach ($collisions as $phone => $ids) {
                $this->mergeGroup($phone, $ids);
            }
        }

        foreach ($canonicalOf as $id => $canonical) {
            $customer = Customer::find($id);
            if (! $customer || $customer->phone === $canonical) {
                continue;
            }
            // A survivor may already hold this number after a merge.
            if (Customer::where('phone', $canonical)->where('id', '!=', $id)->exists()) {
                continue;
            }
            $customer->forceFill(['phone' => $canonical])->saveQuietly();
        }
    }

    /**
     * Fold duplicate customers into the one with the most orders, moving every
     * row that points at the losers first so nothing is orphaned.
     */
    protected function mergeGroup(string $phone, array $ids): void
    {
        $customers = Customer::whereIn('id', $ids)->get();
        if ($customers->count() < 2) {
            return;
        }

        $keep = $customers->sortByDesc(fn ($c) => [(int) $c->total_orders, (float) $c->total_spent, -$c->id])->first();
        $loserIds = $customers->where('id', '!=', $keep->id)->pluck('id')->all();

        DB::transaction(function () use ($keep, $loserIds, $phone) {
            foreach (['orders', 'addresses', 'reviews', 'product_loves', 'push_subscriptions'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    DB::table($table)->whereIn('customer_id', $loserIds)->update(['customer_id' => $keep->id]);
                }
            }

            // Keep whatever detail the duplicates had that the survivor lacks.
            $extra = Customer::whereIn('id', $loserIds)->get();
            $keep->forceFill([
                'phone' => $phone,
                'email' => $keep->email ?: $extra->pluck('email')->filter()->first(),
                'password' => $keep->password ?: $extra->pluck('password')->filter()->first(),
                'points' => (int) $keep->points + (int) $extra->sum('points'),
            ])->saveQuietly();

            Customer::whereIn('id', $loserIds)->delete();
        });

        // Totals are derived from orders, so recompute after re-pointing them.
        $orders = Order::where('customer_id', $keep->id)->whereNotIn('status', ['cancelled', 'returned']);
        $keep->forceFill([
            'total_orders' => (clone $orders)->count(),
            'total_spent' => (clone $orders)->sum('total'),
            'last_order_at' => (clone $orders)->max('created_at'),
        ])->saveQuietly();

        $this->line("  merged ".count($loserIds)." duplicate(s) into customer #{$keep->id} ({$phone})");
    }
}
