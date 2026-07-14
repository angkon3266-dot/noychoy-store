<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;

/**
 * Editable templates for automated/transactional web pushes (order updates,
 * back-in-stock, etc.). Each template is a title + body stored in Settings,
 * with {placeholders} substituted at send time. Defaults are bilingual-friendly
 * and can be edited from the admin Notifications page.
 */
class PushTemplateService
{
    /**
     * Trigger => [label, default title, default body]. Body/title support the
     * placeholders {name}, {order}, {total}, {tracking}, {product}.
     *
     * @return array<string, array{label:string, title:string, body:string}>
     */
    public static function defaults(): array
    {
        return [
            'order_confirmed' => [
                'label' => 'Order confirmed',
                'title' => 'অর্ডার কনফার্ম হয়েছে ✅',
                'body' => '{name}, আপনার অর্ডার #{order} কনফার্ম হয়েছে। আমরা শীঘ্রই এটি প্রস্তুত করছি।',
            ],
            'order_shipped' => [
                'label' => 'Order shipped / handed to courier',
                'title' => 'আপনার পার্সেলটি রওনা দিয়েছে! 📦',
                'body' => 'আপনার জুয়েলারি অর্ডার #{order} কুরিয়ারে হ্যান্ডওভার করা হয়েছে। খুব শীঘ্রই এটি আপনার কাছে পৌঁছে যাবে।',
            ],
            'order_delivered' => [
                'label' => 'Order delivered',
                'title' => 'অর্ডার ডেলিভারড হয়েছে 🎉',
                'body' => '{name}, আপনার অর্ডার #{order} ডেলিভারড হয়েছে। আপনার কেনাকাটার জন্য ধন্যবাদ! ❤️',
            ],
        ];
    }

    public function enabled(string $trigger): bool
    {
        return (bool) Setting::get("push_tpl_{$trigger}_enabled", true);
    }

    public function title(string $trigger): string
    {
        return (string) (Setting::get("push_tpl_{$trigger}_title") ?: (self::defaults()[$trigger]['title'] ?? ''));
    }

    public function body(string $trigger): string
    {
        return (string) (Setting::get("push_tpl_{$trigger}_body") ?: (self::defaults()[$trigger]['body'] ?? ''));
    }

    /**
     * Render a template for an order into a push payload, or null if disabled.
     *
     * @return array{title:string, body:string, url:string, tag:string}|null
     */
    public function forOrder(string $trigger, Order $order): ?array
    {
        if (! $this->enabled($trigger)) {
            return null;
        }

        $map = [
            '{name}' => $order->customer_name ?: ($order->customer->name ?? 'Hello'),
            '{order}' => $order->order_number,
            '{total}' => money($order->total),
            '{tracking}' => $order->shipment->tracking_code ?? '',
        ];

        return [
            'title' => strtr($this->title($trigger), $map),
            'body' => strtr($this->body($trigger), $map),
            'url' => route('account.order', $order->order_number),
            'tag' => 'order-'.$order->id.'-'.$trigger,
        ];
    }
}
