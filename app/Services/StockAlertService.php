<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLove;
use App\Models\Setting;
use App\Models\StockWatcher;

/**
 * Fires web-push alerts off product changes: "back in stock" to everyone who
 * asked to be notified, and "price drop" to everyone who loved the product.
 */
class StockAlertService
{
    public function __construct(
        protected PushTemplateService $templates,
        protected NotificationService $notifications,
    ) {}

    public function handleProductChange(Product $product, bool $wasAvailable, float $oldPrice): void
    {
        if ($product->status !== 'published') {
            return;
        }

        if (! $wasAvailable && $product->isAvailable()) {
            $this->notifyBackInStock($product);
        }

        if ($oldPrice > 0 && (float) $product->price < $oldPrice && $this->priceDropQualifies($product, $oldPrice)) {
            $this->notifyPriceDrop($product, $oldPrice);
            $product->forceFill(['price_drop_notified_at' => now()])->saveQuietly();
        }
    }

    /**
     * A price drop is worth pushing only if it clears the minimum-drop threshold
     * and we haven't already alerted for this product in the last 24 hours — so a
     * ৳1 tweak or repeated edits don't spam everyone who loved it.
     */
    protected function priceDropQualifies(Product $product, float $oldPrice): bool
    {
        $dropPercent = ($oldPrice - (float) $product->price) / $oldPrice * 100;
        $minPercent = (float) Setting::get('pricedrop_min_percent', 5);
        if ($dropPercent < $minPercent) {
            return false;
        }

        return ! ($product->price_drop_notified_at && $product->price_drop_notified_at->gt(now()->subDay()));
    }

    /** Push to everyone watching this product, then clear the watch list. */
    public function notifyBackInStock(Product $product): void
    {
        $watchers = StockWatcher::where('product_id', $product->id)->get();
        if ($watchers->isEmpty()) {
            return;
        }

        $payload = $this->templates->forProduct('back_in_stock', $product);
        if ($payload) {
            $this->notifications->pushToSubscriptionIds(
                $watchers->pluck('push_subscription_id')->all(),
                $payload,
            );
        }

        StockWatcher::whereIn('id', $watchers->pluck('id'))->delete();
    }

    /** Push to members who loved this product when its price drops. */
    public function notifyPriceDrop(Product $product, float $oldPrice): void
    {
        $customerIds = ProductLove::where('product_id', $product->id)
            ->whereNotNull('customer_id')->pluck('customer_id')->unique();
        if ($customerIds->isEmpty()) {
            return;
        }

        $subIds = \App\Models\PushSubscription::whereIn('customer_id', $customerIds)->pluck('id')->all();
        if (empty($subIds)) {
            return;
        }

        $payload = $this->templates->forProduct('price_drop', $product, [
            '{price}' => money($product->price),
            '{old_price}' => money($oldPrice),
        ]);
        if ($payload) {
            $this->notifications->pushToSubscriptionIds($subIds, $payload);
        }
    }
}
