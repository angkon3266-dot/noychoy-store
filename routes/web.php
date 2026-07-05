<?php

use App\Http\Controllers\Customer\AccountController;
use App\Http\Controllers\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\SteadfastWebhookController;
use Illuminate\Support\Facades\Route;

// Steadfast delivery-status webhook (register at steadfast.com.bd/user/webhook/add)
Route::post('/webhooks/steadfast', [SteadfastWebhookController::class, 'handle'])->name('steadfast.webhook');

// Meta (Facebook) webhook — subscription verification (GET) + event delivery (POST).
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->name('meta.webhook.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'handle'])->name('meta.webhook');

// ── Storefront ──────────────────────────────────────────────────────────────
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/shop', [CatalogController::class, 'index'])->name('shop');
Route::get('/search/suggest', [CatalogController::class, 'suggest'])->name('search.suggest');

// Meta (Facebook/Instagram) product catalog feed for Commerce Manager
Route::get('/feed/meta.csv', [\App\Http\Controllers\Shop\ProductFeedController::class, 'meta'])->name('feed.meta');
Route::get('/track', [CheckoutController::class, 'track'])->name('track');

// Cart
Route::controller(CartController::class)->group(function () {
    Route::get('/cart', 'index')->name('cart');
    Route::get('/cart/mini', 'mini')->name('cart.mini');
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

// Checkout
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::post('/checkout/lead', [\App\Http\Controllers\Shop\LeadController::class, 'capture'])->name('checkout.lead');

// Product reviews
Route::post('/product/{product:slug}/review', [\App\Http\Controllers\Shop\ReviewController::class, 'store'])->name('review.store');

// Love / heart reaction (anonymous, per-browser cookie)
Route::post('/product/{product:slug}/love', [\App\Http\Controllers\Shop\LoveController::class, 'toggle'])->name('product.love');
Route::get('/order/{orderNumber}/confirmation', [CheckoutController::class, 'confirmation'])->name('order.confirmation');

// ── Customer accounts (optional) ─────────────────────────────────────────────
Route::middleware('guest:customer')->group(function () {
    Route::get('/login', [CustomerAuthController::class, 'showLogin'])->name('customer.login');
    Route::post('/login', [CustomerAuthController::class, 'login'])->name('customer.login.post');
    Route::get('/register', [CustomerAuthController::class, 'showRegister'])->name('customer.register');
    Route::post('/register', [CustomerAuthController::class, 'register'])->name('customer.register.post');

    // Continue with Google (OAuth2, no Socialite)
    Route::get('/auth/google', [\App\Http\Controllers\Customer\GoogleController::class, 'redirect'])->name('customer.google');

    // Forgot password via SMS OTP
    Route::get('/password/forgot', [\App\Http\Controllers\Customer\PasswordResetController::class, 'showForgot'])->name('customer.password.forgot');
    Route::post('/password/forgot', [\App\Http\Controllers\Customer\PasswordResetController::class, 'sendOtp'])->name('customer.password.send');
    Route::get('/password/reset', [\App\Http\Controllers\Customer\PasswordResetController::class, 'showReset'])->name('customer.password.reset');
    Route::post('/password/reset', [\App\Http\Controllers\Customer\PasswordResetController::class, 'reset'])->name('customer.password.update');

    // Forgot password via email link
    Route::post('/password/email', [\App\Http\Controllers\Customer\PasswordResetController::class, 'sendEmailLink'])->name('customer.password.email');
    Route::get('/password/reset-email', [\App\Http\Controllers\Customer\PasswordResetController::class, 'showEmailReset'])->name('customer.password.email.form');
    Route::post('/password/reset-email', [\App\Http\Controllers\Customer\PasswordResetController::class, 'resetViaEmail'])->name('customer.password.email.update');
});

// Google OAuth callback (outside guest group so it works mid-session)
Route::get('/auth/google/callback', [\App\Http\Controllers\Customer\GoogleController::class, 'callback'])->name('customer.google.callback');
Route::post('/logout', [CustomerAuthController::class, 'logout'])->name('customer.logout');

Route::middleware('auth:customer')->group(function () {
    Route::get('/account', [AccountController::class, 'index'])->name('account');
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

    // Loyalty — social share reward
    Route::post('/account/share', [AccountController::class, 'share'])->name('account.share');
});

Route::get('/discover', [\App\Http\Controllers\Shop\DiscoverController::class, 'index'])->name('discover');

// Catalog (slug routes last so they don't shadow the above)
Route::get('/category/{category:slug}', [CatalogController::class, 'category'])->name('category.show');
Route::get('/product/{product:slug}', [CatalogController::class, 'show'])->name('product.show');
