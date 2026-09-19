<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Rebuild every customer's order count and spend from the orders that are
 * sales (Customer::recountOrders).
 *
 * Needed once after 2026-09-19: until then a cancelled or returned order stayed
 * in both figures for good, so customers who cancelled still carried the money
 * in Top customers, the repeat flag and spend-based segments. From that date
 * the figures follow every status change by themselves; this is for the rows
 * written before.
 *
 * Safe by default: reports what would change and writes nothing until --force.
 */
class RecountCustomerOrders extends Command
{
    protected $signature = 'customers:recount
        {--force : Actually write the new figures (otherwise this is a dry run)}';

    protected $description = 'Rebuild customers\' order counts and spend without cancelled or returned orders';

    public function handle(): int
    {
        $write = (bool) $this->option('force');

        if (! $write) {
            $this->warn('Dry run — nothing will be written. Add --force to apply.');
        }

        $changed = 0;

        Customer::query()->orderBy('id')->chunkById(200, function ($customers) use ($write, &$changed) {
            foreach ($customers as $customer) {
                $sales = $customer->orders()->whereNotIn('status', Order::NOT_SALES);
                $orders = (clone $sales)->count();
                $spent = round((float) (clone $sales)->sum('total'), 2);

                if ($orders === (int) $customer->total_orders && abs($spent - (float) $customer->total_spent) < 0.01) {
                    continue;
                }

                $changed++;
                // Ids only: this output lands in terminals and logs, and a
                // customer's name does not need to go with it.
                $this->line(sprintf(
                    '  customer #%d: %d orders / %s  →  %d orders / %s',
                    $customer->id, (int) $customer->total_orders, money($customer->total_spent), $orders, money($spent),
                ));

                if ($write) {
                    $customer->recountOrders();
                }
            }
        });

        $this->info($changed === 0
            ? 'Every customer already matches.'
            : ($write ? "Updated {$changed} customer(s)." : "{$changed} customer(s) would change."));

        return self::SUCCESS;
    }
}
