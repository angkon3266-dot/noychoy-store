<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class NotifyNewArrivals extends Command
{
    protected $signature = 'notifications:new-arrivals';

    protected $description = 'Announce newly published products to members as one batched notification (does nothing if there are none).';

    public function handle(NotificationService $notify): int
    {
        if (! $notify->autoEnabled('notify_new_arrivals')) {
            $this->info('New-arrivals notifications are turned off.');

            return self::SUCCESS;
        }

        $new = Product::where('status', 'published')->whereNull('announced_at')->get();

        if ($new->isEmpty()) {
            $this->info('No new products to announce.');

            return self::SUCCESS;
        }

        $count = $new->count();
        $notify->broadcast([
            'type' => 'new_arrival',
            'title' => $count === 1 ? 'New arrival: '.$new->first()->name : $count.' new arrivals just dropped ✨',
            'body' => 'Fresh pieces are in — be the first to explore them.',
            'url' => route('shop').'?sort=new',
            'cta_label' => 'Explore new arrivals',
        ]);

        Product::whereIn('id', $new->pluck('id'))->update(['announced_at' => now()]);

        $this->info("Announced {$count} new product(s) to members.");

        return self::SUCCESS;
    }
}
