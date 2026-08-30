<?php

use App\Http\Controllers\Customer\AccountController;
use App\Http\Controllers\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Customer\GoogleController;
use App\Http\Controllers\Customer\PasswordResetController;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\DiscoverController;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Controllers\Shop\LandingController;
use App\Http\Controllers\Shop\LeadController;
use App\Http\Controllers\Shop\LoveController;
use App\Http\Controllers\Shop\ManifestController;
use App\Http\Controllers\Shop\PageController;
use App\Http\Controllers\Shop\ProductFeedController;
use App\Http\Controllers\Shop\PushController;
use App\Http\Controllers\Shop\ReviewController;
use App\Http\Controllers\Shop\SitemapController;
use App\Http\Controllers\SteadfastWebhookController;
use Illuminate\Support\Facades\Route;

// Steadfast delivery-status webhook (register at steadfast.com.bd/user/webhook/add)
Route::post('/webhooks/steadfast', [SteadfastWebhookController::class, 'handle'])->name('steadfast.webhook');

// Meta (Facebook) webhook — subscription verification (GET) + event delivery (POST).
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->name('meta.webhook.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'handle'])->name('meta.webhook');

// ── Storefront ──────────────────────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/site.webmanifest', ManifestController::class)->name('manifest');

// Marketing landing pages built in the admin section builder.
Route::get('/lp/{slug}', [LandingController::class, 'show'])->name('landing.show');
// Honours System Config → SEO. Both settings existed but were ignored here,
// so switching the shop to "noindex" (a staging copy, a store not open yet)
// silently did nothing at all — the worst kind of setting.
Route::get('/robots.txt', function () {
    $private = config('seo.robots') === 'noindex';

    // Utility paths only. Filtered and sorted catalog URLs (?sort=, ?colors[]=,
    // ?q=) are deliberately NOT blocked here: they carry `noindex, follow` from
    // App\Support\Seo\Meta, and a page Google is forbidden to fetch is a page
    // whose noindex Google never reads. On a catalogue this size crawl budget is
    // not the constraint, so letting the crawler in and telling it plainly beats
    // slamming the door and hoping.
    $disallow = [
        '/admin', '/cart', '/checkout', '/account', '/login', '/register',
        '/logout', '/password/', '/order/', '/track', '/search/suggest',
    ];

    $body = $private
        ? "User-agent: *\nDisallow: /\n"
        : "User-agent: *\n"
            .collect($disallow)->map(fn ($p) => "Disallow: {$p}\n")->implode('')
            ."Allow: /\n";

    // A sitemap line pointing at a disabled (404) sitemap is a crawl error, and
    // there is nothing to advertise on a site asking not to be indexed.
    if (! $private && config('seo.sitemap_enabled', true)) {
        $body .= "\nSitemap: ".route('sitemap')."\n";
    }

    return response($body, 200, ['Content-Type' => 'text/plain']);
})->name('robots');
Route::get('/shop', [CatalogController::class, 'index'])->name('shop');
Route::get('/best-sellers', [CatalogController::class, 'bestSellers'])->name('best-sellers');

// Web-push subscription (public — works for guests and members).
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/push/watch-stock', [PushController::class, 'watchStock'])->name('push.watch-stock');
});
Route::get('/search/suggest', [CatalogController::class, 'suggest'])->name('search.suggest');

// Meta (Facebook/Instagram) product catalog feed for Commerce Manager
Route::get('/feed/meta.csv', [ProductFeedController::class, 'meta'])->name('feed.meta');
// Throttled: order numbers are sequential, so an unthrottled lookup would let
// someone who knows a phone number enumerate their way to the matching order.
Route::get('/track', [CheckoutController::class, 'track'])->name('track')->middleware('throttle:20,1');

// Cart
Route::controller(CartController::class)->group(function () {
    Route::get('/cart', 'index')->name('cart');
    Route::get('/cart/mini', 'mini')->name('cart.mini');
    // Write endpoints are throttled generously (anti-spam, not anti-human).
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/cart/add/{product:slug}', 'add')->name('cart.add');
        Route::post('/cart/add-many', 'addMany')->name('cart.add-many');
        Route::post('/cart/buy-now/{product:slug}', 'buyNow')->name('cart.buynow');
        Route::patch('/cart/update', 'update')->name('cart.update');
        Route::delete('/cart/remove', 'remove')->name('cart.remove');
        Route::post('/cart/coupon', 'applyCoupon')->name('cart.coupon');
        Route::delete('/cart/coupon', 'removeCoupon')->name('cart.coupon.remove');
        Route::post('/cart/points', 'applyPoints')->name('cart.points');
        Route::delete('/cart/points', 'removePoints')->name('cart.points.remove');
    });
});

// Checkout
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store')->middleware('throttle:10,1');
Route::post('/checkout/lead', [LeadController::class, 'capture'])->name('checkout.lead')->middleware('throttle:15,1');

// Rebuilds a saved cart from the abandoned-cart reminder SMS. Signed, because
// a cart's contents are the shopper's business and the id is guessable.
Route::get('/cart/restore/{cart}', [CartController::class, 'restore'])
    ->name('cart.restore')->middleware('throttle:20,10');

// Product reviews (photo uploads — keep tight)
Route::post('/product/{product:slug}/review', [ReviewController::class, 'store'])->name('review.store')->middleware('throttle:5,10');

// Love / heart reaction (anonymous, per-browser cookie)
Route::post('/product/{product:slug}/love', [LoveController::class, 'toggle'])->name('product.love')->middleware('throttle:60,1');
Route::get('/order/{orderNumber}/confirmation', [CheckoutController::class, 'confirmation'])->name('order.confirmation');
// Where the post-delivery review request lands. Declared here, above the
// /product/{slug} catch-all, and gated by a signature rather than a login —
// this store is COD and most buyers never register.
Route::get('/order/{orderNumber}/review', [ReviewController::class, 'invite'])->name('order.review');
// The short form of the same link, for SMS — a signed URL's 64-character
// signature is most of a 160-character message. Throttled because its token is
// shorter than a full signature, so guessing must stay expensive.
Route::get('/r/{orderNumber}/{token}', [ReviewController::class, 'shortInvite'])
    ->name('order.review.short')->middleware('throttle:20,1');
// Turns the guest customer row a checkout created into a real login. Gated by
// proof the order is yours, never by the phone number alone.
Route::post('/order/{orderNumber}/claim', [CheckoutController::class, 'claimAccount'])
    ->name('order.claim')->middleware('throttle:10,10');

// ── Customer accounts (optional) ─────────────────────────────────────────────
Route::middleware('guest:customer')->group(function () {
    Route::get('/login', [CustomerAuthController::class, 'showLogin'])->name('customer.login');
    Route::post('/login', [CustomerAuthController::class, 'login'])->name('customer.login.post')->middleware('throttle:login');
    Route::get('/register', [CustomerAuthController::class, 'showRegister'])->name('customer.register');
    Route::post('/register', [CustomerAuthController::class, 'register'])->name('customer.register.post')->middleware('throttle:5,1');

    // Continue with Google (OAuth2, no Socialite)
    Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('customer.google');

    // Forgot password via SMS OTP (throttled per phone + IP — sends paid SMS)
    Route::get('/password/forgot', [PasswordResetController::class, 'showForgot'])->name('customer.password.forgot');
    Route::post('/password/forgot', [PasswordResetController::class, 'sendOtp'])->name('customer.password.send')->middleware('throttle:otp');
    Route::get('/password/reset', [PasswordResetController::class, 'showReset'])->name('customer.password.reset');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->name('customer.password.update')->middleware('throttle:10,1');

    // Forgot password via email link
    Route::post('/password/email', [PasswordResetController::class, 'sendEmailLink'])->name('customer.password.email')->middleware('throttle:otp');
    Route::get('/password/reset-email', [PasswordResetController::class, 'showEmailReset'])->name('customer.password.email.form');
    Route::post('/password/reset-email', [PasswordResetController::class, 'resetViaEmail'])->name('customer.password.email.update')->middleware('throttle:10,1');
});

// Google OAuth callback (outside guest group so it works mid-session)
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('customer.google.callback');
Route::post('/logout', [CustomerAuthController::class, 'logout'])->name('customer.logout');

Route::middleware('auth:customer')->group(function () {
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::get('/account/notifications', [AccountController::class, 'notifications'])->name('account.notifications');
    Route::post('/account/notifications/read', [AccountController::class, 'markNotificationsRead'])->name('account.notifications.read');
    Route::get('/account/n/{notification}', [AccountController::class, 'trackNotification'])->name('account.notifications.go');
    Route::post('/account/push/subscribe', [AccountController::class, 'subscribePush'])->name('account.push.subscribe');
    Route::post('/account/push/unsubscribe', [AccountController::class, 'unsubscribePush'])->name('account.push.unsubscribe');
    Route::get('/account/orders', [AccountController::class, 'orders'])->name('account.orders');
    Route::get('/account/orders/{orderNumber}', [AccountController::class, 'order'])->name('account.order');
    Route::post('/account/orders/{orderNumber}/reorder', [AccountController::class, 'reorder'])->name('account.reorder');

    // Profile & security
    Route::get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::patch('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::patch('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');

    // Addresses
    Route::get('/account/addresses', [AccountController::class, 'addresses'])->name('account.addresses');
    Route::post('/account/addresses', [AccountController::class, 'storeAddress'])->name('account.addresses.store');
    Route::patch('/account/addresses/{address}', [AccountController::class, 'updateAddress'])->name('account.addresses.update');
    Route::delete('/account/addresses/{address}', [AccountController::class, 'deleteAddress'])->name('account.addresses.delete');
    Route::post('/account/addresses/{address}/default', [AccountController::class, 'setDefaultAddress'])->name('account.addresses.default');

    // Reviews & loved
    Route::get('/account/reviews', [AccountController::class, 'reviews'])->name('account.reviews');
    Route::get('/account/loved', [AccountController::class, 'loved'])->name('account.loved');

});

Route::get('/discover', [DiscoverController::class, 'index'])->name('discover');

// Footer content pages (about / privacy / terms / refund) + contact.
Route::get('/about', [PageController::class, 'legal'])->defaults('page', 'about')->name('page.about');
Route::get('/privacy-policy', [PageController::class, 'legal'])->defaults('page', 'privacy')->name('page.privacy');
Route::get('/terms-and-conditions', [PageController::class, 'legal'])->defaults('page', 'terms')->name('page.terms');
Route::get('/refund-policy', [PageController::class, 'legal'])->defaults('page', 'refund')->name('page.refund');
Route::get('/contact', [PageController::class, 'contact'])->name('page.contact');
Route::post('/contact', [PageController::class, 'submitContact'])->name('page.contact.submit');

// Catalog (slug routes last so they don't shadow the above)
Route::get('/collection/{collection:slug}', [CatalogController::class, 'collection'])->name('collection.show');
Route::get('/category/{category:slug}', [CatalogController::class, 'category'])->name('category.show');
Route::get('/product/{product:slug}', [CatalogController::class, 'show'])->name('product.show');
