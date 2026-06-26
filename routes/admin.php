<?php

use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsController;
use Illuminate\Support\Facades\Route;

// Auth (no admin middleware)
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->name('login.post');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// Protected admin area
Route::middleware('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Products
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
    Route::get('products/import', [ProductController::class, 'importForm'])->name('products.import');
    Route::post('products/import', [ProductController::class, 'import'])->name('products.import.store');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
    Route::patch('products/{product}/quick', [ProductController::class, 'quickUpdate'])->name('products.quick');
    Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::delete('product-images/{image}', [ProductController::class, 'deleteImage'])->name('products.images.delete');
    Route::post('product-images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.images.primary');

    // Categories
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Orders
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/labels', [OrderController::class, 'labels'])->name('orders.labels');
    Route::post('orders/bulk-steadfast', [OrderController::class, 'bulkSteadfast'])->name('orders.bulk-steadfast');
    Route::post('orders/merge', [OrderController::class, 'merge'])->name('orders.merge');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::post('orders/{order}/steadfast', [OrderController::class, 'pushToSteadfast'])->name('orders.steadfast');
    Route::post('orders/{order}/steadfast/refresh', [OrderController::class, 'refreshShipment'])->name('orders.steadfast.refresh');
    Route::post('orders/{order}/sms', [OrderController::class, 'sendSms'])->name('orders.sms');

    // Customers (CRM, analytics, SMS, import)
    Route::get('customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/import', [\App\Http\Controllers\Admin\CustomerController::class, 'importForm'])->name('customers.import');
    Route::post('customers/import', [\App\Http\Controllers\Admin\CustomerController::class, 'import'])->name('customers.import.store');
    Route::get('customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'show'])->name('customers.show');
    Route::put('customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'update'])->name('customers.update');
    Route::post('customers/{customer}/sms', [\App\Http\Controllers\Admin\CustomerController::class, 'sendSms'])->name('customers.sms');
    Route::get('customers-export', [\App\Http\Controllers\Admin\CustomerController::class, 'export'])->name('customers.export');
    Route::post('customers/{customer}/offers', [\App\Http\Controllers\Admin\CustomerController::class, 'storeOffer'])->name('customers.offers.store');
    Route::delete('customers/{customer}/offers/{offer}', [\App\Http\Controllers\Admin\CustomerController::class, 'destroyOffer'])->name('customers.offers.destroy');
    Route::post('customers/{customer}/points', [\App\Http\Controllers\Admin\CustomerController::class, 'adjustPoints'])->name('customers.points');
    Route::post('customers/bulk-offer', [\App\Http\Controllers\Admin\CustomerController::class, 'bulkOffer'])->name('customers.bulk-offer');

    // Offers & promotions (auto-apply + PDP display)
    Route::get('offers', [\App\Http\Controllers\Admin\OfferController::class, 'index'])->name('offers.index');
    Route::post('offers', [\App\Http\Controllers\Admin\OfferController::class, 'store'])->name('offers.store');
    Route::put('offers/{offer}', [\App\Http\Controllers\Admin\OfferController::class, 'update'])->name('offers.update');
    Route::delete('offers/{offer}', [\App\Http\Controllers\Admin\OfferController::class, 'destroy'])->name('offers.destroy');
    Route::post('offers/register-discount', [\App\Http\Controllers\Admin\OfferController::class, 'saveRegisterOffer'])->name('offers.register');
    Route::post('offers/loyalty', [\App\Http\Controllers\Admin\OfferController::class, 'saveLoyalty'])->name('offers.loyalty');

    // Media library (browse / optimize / delete uploaded images & videos)
    Route::get('media', [\App\Http\Controllers\Admin\MediaController::class, 'index'])->name('media.index');
    Route::post('media/optimize', [\App\Http\Controllers\Admin\MediaController::class, 'optimize'])->name('media.optimize');
    Route::delete('media', [\App\Http\Controllers\Admin\MediaController::class, 'destroy'])->name('media.destroy');

    // Suppliers & purchase orders (sourcing / procurement)
    Route::get('suppliers', [\App\Http\Controllers\Admin\SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [\App\Http\Controllers\Admin\SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('suppliers/{supplier}', [\App\Http\Controllers\Admin\SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('suppliers/{supplier}', [\App\Http\Controllers\Admin\SupplierController::class, 'destroy'])->name('suppliers.destroy');

    Route::get('purchase-orders', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('purchase-orders/create', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('purchase-orders', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('purchase-orders/{purchaseOrder}', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('purchase-orders/{purchaseOrder}/edit', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit');
    Route::put('purchase-orders/{purchaseOrder}', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/status', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'updateStatus'])->name('purchase-orders.status');
    Route::get('purchase-orders/{purchaseOrder}/export', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'export'])->name('purchase-orders.export');
    Route::post('purchase-orders-fetch-image', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'fetchImage'])->name('purchase-orders.fetch-image');
    Route::delete('purchase-orders/{purchaseOrder}', [\App\Http\Controllers\Admin\PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');

    // Reviews (moderation)
    Route::get('reviews', [\App\Http\Controllers\Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::patch('reviews/{review}/status', [\App\Http\Controllers\Admin\ReviewController::class, 'updateStatus'])->name('reviews.status');
    Route::delete('reviews/{review}', [\App\Http\Controllers\Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Abandoned carts (lead follow-up)
    Route::get('abandoned-carts', [\App\Http\Controllers\Admin\AbandonedCartController::class, 'index'])->name('abandoned.index');
    Route::patch('abandoned-carts/{cart}/contacted', [\App\Http\Controllers\Admin\AbandonedCartController::class, 'markContacted'])->name('abandoned.contacted');
    Route::delete('abandoned-carts/{cart}', [\App\Http\Controllers\Admin\AbandonedCartController::class, 'destroy'])->name('abandoned.destroy');

    // Coupons
    Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');

    // SMS
    Route::get('sms', [SmsController::class, 'index'])->name('sms.index');
    Route::post('sms/send', [SmsController::class, 'send'])->name('sms.send');
    Route::post('sms/broadcast', [SmsController::class, 'broadcast'])->name('sms.broadcast');

    // Appearance / theme
    Route::get('appearance', [AppearanceController::class, 'index'])->name('appearance');
    Route::post('appearance', [AppearanceController::class, 'update'])->name('appearance.update');

    // Navigation menu builder
    Route::get('menu', [MenuController::class, 'index'])->name('menu');
    Route::post('menu', [MenuController::class, 'update'])->name('menu.update');

    // Integrations (Steadfast, SMS) + templates
    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('integrations', [IntegrationController::class, 'update'])->name('integrations.update');
    Route::post('integrations/test-sms', [IntegrationController::class, 'testSms'])->name('integrations.test-sms');

    // Staff accounts & roles (admin only)
    Route::get('users', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('users.index');
    Route::post('users', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('users.destroy');

    // My profile (any admin user — change own name/email/password)
    Route::get('profile', [\App\Http\Controllers\Admin\ProfileController::class, 'edit'])->name('profile');
    Route::put('profile', [\App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('profile.update');

    // Settings
    Route::get('settings', [SettingController::class, 'index'])->name('settings');
    Route::post('settings', [SettingController::class, 'update'])->name('settings.update');
});
