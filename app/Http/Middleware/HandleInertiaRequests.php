<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Services\CartService;
use App\Services\NotificationService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shared props for every Inertia (React storefront) page.
 *
 * The `chrome` prop mirrors what the Blade layout's view composer +
 * inline @php blocks used to compute: menu, logos, announcement bar,
 * footer content, floating buttons. It is a closure so partial reloads
 * (router.reload({ only: [...] })) skip the queries entirely.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'inertia';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'errors' => fn () => $request->session()->get('errors')
                ? $request->session()->get('errors')->getBag('default')->getMessages()
                : (object) [],

            // Fresh on every response — the badge must never lag an add-to-cart.
            'cart' => fn () => ['count' => app(CartService::class)->count()],

            'customer' => function () {
                $c = auth('customer')->user();

                return $c ? [
                    'name' => $c->name,
                    'first' => str($c->name)->trim()->explode(' ')->first(),
                ] : null;
            },

            'chrome' => fn () => $this->chrome(),
        ]);
    }

    protected function chrome(): array
    {
        $nav = Category::active()->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->active()])
            ->orderBy('position')->get();

        $footerIds = collect(theme('footer_category_ids') ?? [])->map(fn ($i) => (int) $i)->filter();
        $footerCats = $footerIds->isEmpty()
            ? $nav
            : Category::active()->whereIn('id', $footerIds)->get()
                ->sortBy(fn ($c) => $footerIds->search($c->id))->values();

        $customer = auth('customer')->user();
        $announceMsgs = array_values(array_filter((array) (theme('announcement_messages') ?? [])));

        return [
            'storeName' => store_name(),
            'announcement' => theme('announcement_enabled') && $announceMsgs ? [
                'messages' => $announceMsgs,
                'speed' => max(10, count($announceMsgs) * (int) (theme('announcement_speed') ?? 6)),
                'bg' => theme('announcement_bg'),
                'color' => theme('announcement_color'),
                'link' => theme('announcement_link'),
            ] : null,
            'logo' => [
                'desktop' => theme_asset(theme('logo')),
                'mobile' => theme_asset(theme('logo_mobile')) ?: theme_asset(theme('logo')),
                'hDesktop' => (int) (theme('logo_height_desktop') ?: 40),
                'hMobile' => (int) (theme('logo_height_mobile') ?: 32),
                'align' => theme('logo_align', 'left'),
                'center' => theme_asset(theme('header_center_image')),
                'centerLink' => theme('header_center_link') ?: route('home'),
                'centerH' => (int) (theme('header_center_height') ?: 32),
            ],
            'menuIcon' => [
                'url' => theme_asset(theme('menu_icon')),
                'rotation' => (int) (theme('menu_icon_rotation') ?? 45),
                'height' => (int) (theme('menu_icon_height') ?: 28),
            ],
            'menu' => [
                'items' => site_menu(),
                'trigger' => theme('menu_desktop_trigger', 'hover'),
                'showSearch' => (bool) theme('menu_show_search', true),
                'cta' => theme('menu_cta_label') ? [
                    'label' => theme('menu_cta_label'),
                    'url' => theme('menu_cta_link') ?: route('shop'),
                ] : null,
            ],
            'notifications' => $customer ? $this->notifications($customer) : null,
            'memberBar' => $customer && theme('cbar_enabled') && filled(theme('cbar_text')) ? [
                'text' => str_replace('{name}', str($customer->name)->trim()->explode(' ')->first(), theme('cbar_text')),
                'code' => theme('cbar_code'),
                'bg' => theme('cbar_bg'),
                'color' => theme('cbar_color'),
                'link' => theme('cbar_link'),
                'linkLabel' => theme('cbar_link_label') ?: 'Shop now',
                'key' => md5(theme('cbar_text').theme('cbar_code')),
            ] : null,
            'offers' => $customer ? $this->memberOffers($customer) : null,
            'floats' => [
                'share' => (bool) theme('show_share_button', true),
                'call' => theme('show_call_button') ? \App\Models\Setting::get('store_phone', config('store.phone')) : null,
                'messenger' => theme('show_messenger_button') ? theme('messenger_url') : null,
                'whatsapp' => theme('show_whatsapp_button') ? theme('whatsapp_number') : null,
            ],
            'footer' => [
                'brand' => theme('footer_brand') ?: store_name(),
                'about' => theme('footer_about') ?: 'Handpicked jewelry, delivered across Bangladesh. Cash on delivery available.',
                'showTrust' => (bool) theme('footer_show_trust'),
                'trustBadges' => collect(theme('trust_badges') ?? [])
                    ->filter(fn ($b) => filled($b['title'] ?? null))->take(3)->values(),
                'categories' => $footerCats->map(fn ($c) => [
                    'name' => $c->name,
                    'url' => route('category.show', $c),
                ])->values(),
                'facebook' => theme('footer_facebook'),
                'instagram' => theme('footer_instagram'),
                'phone' => \App\Models\Setting::get('store_phone', config('store.phone')),
                'email' => \App\Models\Setting::get('store_email', config('store.email')),
                'whatsapp' => theme('whatsapp_number'),
                'copyright' => theme('footer_copyright') ?: '© '.date('Y').' '.store_name().'. All rights reserved.',
            ],
            'urls' => [
                'home' => route('home'),
                'shop' => route('shop'),
                'bestSellers' => route('best-sellers'),
                'discover' => route('discover'),
                'cart' => route('cart'),
                'cartMini' => route('cart.mini'),
                'checkout' => route('checkout'),
                'track' => route('track'),
                'contact' => route('page.contact'),
                'privacy' => route('page.privacy'),
                'terms' => route('page.terms'),
                'refund' => route('page.refund'),
                'login' => route('customer.login'),
                'register' => route('customer.register'),
                'account' => route('account'),
                'accountLoved' => route('account.loved'),
                'accountOrders' => route('account.orders'),
                'accountNotifications' => auth('customer')->check() ? route('account.notifications') : null,
                'notificationsRead' => auth('customer')->check() ? route('account.notifications.read') : null,
                'suggest' => route('search.suggest'),
            ],
        ];
    }

    protected function notifications($customer): array
    {
        $svc = app(NotificationService::class);

        return [
            'unread' => $svc->unreadCountFor($customer),
            'webPushReady' => app(WebPushService::class)->ready(),
            'items' => $svc->recentFor($customer, 10)->map(fn ($n) => [
                'icon' => $n->iconOrDefault(),
                'title' => $n->title,
                'body' => $n->body,
                'time' => $n->sent_at?->diffForHumans(),
                'url' => $n->url ? route('account.notifications.go', $n) : route('account.notifications'),
            ])->values(),
        ];
    }

    protected function memberOffers($customer): ?array
    {
        $offers = $customer->liveOffers()->get();
        $usage = member_pricing()->enabled() ? member_pricing()->usageStatus($customer) : null;
        $hasDiscount = $usage && $usage['percent'] > 0;

        if ($offers->isEmpty() && ! $hasDiscount) {
            return null;
        }

        return [
            'badge' => $offers->count() + ($hasDiscount ? 1 : 0),
            'member' => $hasDiscount ? [
                'percent' => rtrim(rtrim(number_format($usage['percent'], 2), '0'), '.'),
                'capped' => (bool) $usage['capped'],
                'remaining' => $usage['remaining'] ?? null,
                'max' => $usage['max'] ?? null,
                'resets' => $usage['resets_at']?->format('d M'),
            ] : null,
            'items' => $offers->map(fn ($o) => [
                'title' => $o->title,
                'reward' => $o->rewardText(),
                'scope' => $o->applies_to !== 'all' ? $o->scopeLabel() : null,
                'message' => $o->message,
                'until' => $o->expires_at?->format('d M'),
            ])->values(),
        ];
    }
}
