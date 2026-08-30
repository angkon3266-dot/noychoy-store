<?php

use App\Http\Controllers\Admin\AbandonedCartController;
use App\Http\Controllers\Admin\AdminPushController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\ConfigBackupController;
use App\Http\Controllers\Admin\ConfigHistoryController;
use App\Http\Controllers\Admin\ContentTemplateController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DripController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\KnowledgeController;
use App\Http\Controllers\Admin\LandingPageController;
use App\Http\Controllers\Admin\MarketingController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\MetaConnectionController;
use App\Http\Controllers\Admin\MetaDebugController;
use App\Http\Controllers\Admin\MetaIntegrationController;
use App\Http\Controllers\Admin\MetaOAuthController;
use App\Http\Controllers\Admin\MetaQueueController;
use App\Http\Controllers\Admin\MetaSecurityController;
use App\Http\Controllers\Admin\MetaSyncLogController;
use App\Http\Controllers\Admin\MetaTrackingController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SegmentController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\SystemConfigController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Auth (no admin middleware)
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:5,1');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// Protected admin area
Route::middleware('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Notification bell (alerts are computed live; only read state is stored)
    Route::get('alerts/feed', [AlertController::class, 'feed'])->name('alerts.feed');
    Route::post('alerts/read', [AlertController::class, 'read'])->name('alerts.read');
    Route::post('alerts/read-all', [AlertController::class, 'readAll'])->name('alerts.read-all');

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
    Route::post('products/{product}/quick-media', [ProductController::class, 'quickMedia'])->name('products.quick-media');
    Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::post('products/bulk-serials', [ProductController::class, 'bulkSerials'])->name('products.bulk-serials');
    Route::patch('products/{product}/serial', [ProductController::class, 'updateSerial'])->name('products.serial');

    Route::post('dashboard/panels', [DashboardController::class, 'savePanels'])->name('dashboard.panels');
    // Polled by the "on the site right now" card — see DashboardController::live.
    Route::get('dashboard/live', [DashboardController::class, 'live'])->name('dashboard.live');

    // Marketing landing pages
    Route::get('landing', [LandingPageController::class, 'index'])->name('landing.index');
    Route::get('landing/create', [LandingPageController::class, 'create'])->name('landing.create');
    Route::post('landing', [LandingPageController::class, 'store'])->name('landing.store');
    Route::get('landing/{landing}/edit', [LandingPageController::class, 'edit'])->name('landing.edit');
    Route::put('landing/{landing}', [LandingPageController::class, 'update'])->name('landing.update');
    Route::post('landing/{landing}/duplicate', [LandingPageController::class, 'duplicate'])->name('landing.duplicate');
    Route::delete('landing/{landing}', [LandingPageController::class, 'destroy'])->name('landing.destroy');

    // AI knowledge base browser/editor
    Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::post('knowledge/save', [KnowledgeController::class, 'save'])->name('knowledge.save');
    Route::post('knowledge/sync', [KnowledgeController::class, 'sync'])->name('knowledge.sync');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::delete('products/{product}/images', [ProductController::class, 'bulkDeleteImages'])->name('products.images.bulk-delete');
    Route::delete('product-images/{image}', [ProductController::class, 'deleteImage'])->name('products.images.delete');
    Route::post('product-images/{image}/primary', [ProductController::class, 'setPrimaryImage'])->name('products.images.primary');

    // Product-page story sections (builder helpers) + reusable template library
    Route::post('products/section-image', [ProductController::class, 'uploadSectionImage'])->name('products.section-image');
    Route::post('products/{product}/save-template', [ProductController::class, 'saveAsTemplate'])->name('products.save-template');
    Route::get('content-templates', [ContentTemplateController::class, 'index'])->name('content-templates.index');
    Route::post('content-templates', [ContentTemplateController::class, 'store'])->name('content-templates.store');
    Route::put('content-templates/{template}', [ContentTemplateController::class, 'update'])->name('content-templates.update');
    Route::delete('content-templates/{template}', [ContentTemplateController::class, 'destroy'])->name('content-templates.destroy');

    // Categories
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::post('categories/{category}/move', [CategoryController::class, 'move'])->name('categories.move');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Collections — Shopify-style curated groups. Names must all start with
    // 'collections.' so the sidebar active-state and the section gate work.
    Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::get('collections/create', [CollectionController::class, 'create'])->name('collections.create');
    Route::post('collections', [CollectionController::class, 'store'])->name('collections.store');
    Route::post('collections/preview', [CollectionController::class, 'preview'])->name('collections.preview');
    Route::get('collections/{collection}/edit', [CollectionController::class, 'edit'])->name('collections.edit');
    Route::put('collections/{collection}', [CollectionController::class, 'update'])->name('collections.update');
    Route::post('collections/{collection}/move', [CollectionController::class, 'move'])->name('collections.move');
    Route::delete('collections/{collection}', [CollectionController::class, 'destroy'])->name('collections.destroy');

    // Orders
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/labels', [OrderController::class, 'labels'])->name('orders.labels');
    // Thank-you cards (parcel inserts) + their message templates
    Route::get('orders/cards', [OrderController::class, 'cards'])->name('orders.cards');
    Route::post('orders/cards/messages', [OrderController::class, 'saveCardMessages'])->name('orders.cards.messages');
    Route::get('orders/card-templates', [OrderController::class, 'cardSettings'])->name('orders.card-templates');
    Route::post('orders/card-templates', [OrderController::class, 'saveCardSettings'])->name('orders.card-templates.save');
    Route::post('orders/bulk-steadfast', [OrderController::class, 'bulkSteadfast'])->name('orders.bulk-steadfast');
    Route::post('orders/merge', [OrderController::class, 'merge'])->name('orders.merge');
    Route::post('orders/bulk-delete', [OrderController::class, 'bulkDelete'])->name('orders.bulk-delete');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
    Route::post('orders/{order}/restore', [OrderController::class, 'restore'])->name('orders.restore')->withTrashed();
    Route::delete('orders/{order}/force', [OrderController::class, 'forceDelete'])->name('orders.force-delete')->withTrashed();
    // Declared above orders/{order} so "create" is not read as an order id.
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders/create', [OrderController::class, 'storeManual'])->name('orders.store-manual');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::post('orders/{order}/amend', [OrderController::class, 'amend'])->name('orders.amend');
    Route::post('orders/{order}/details', [OrderController::class, 'updateDetails'])->name('orders.details');
    Route::post('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.payment');
    Route::post('orders/{order}/steadfast', [OrderController::class, 'pushToSteadfast'])->name('orders.steadfast');
    Route::post('orders/{order}/steadfast/refresh', [OrderController::class, 'refreshShipment'])->name('orders.steadfast.refresh');
    // On-demand BDCourier lookups — throttled because every call spends plan quota.
    Route::post('orders/{order}/courier-check', [OrderController::class, 'courierCheck'])
        ->middleware('throttle:20,1')->name('orders.courier-check');
    Route::post('orders/bulk-courier-check', [OrderController::class, 'bulkCourierCheck'])
        ->middleware('throttle:10,1')->name('orders.bulk-courier-check');
    Route::post('orders/{order}/sms', [OrderController::class, 'sendSms'])->name('orders.sms');

    // Customers (CRM, analytics, SMS, import)
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/all-offers', [CustomerController::class, 'offersIndex'])->name('customers.all-offers');

    // Customer segments (groups)
    Route::get('segments', [SegmentController::class, 'index'])->name('segments.index');
    Route::post('segments/preview', [SegmentController::class, 'preview'])->name('segments.preview');
    Route::post('segments', [SegmentController::class, 'store'])->name('segments.store');
    Route::put('segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
    Route::post('segments/{segment}/offer', [SegmentController::class, 'grantOffer'])->name('segments.grant-offer');
    Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');

    // Member notifications hub
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications', [NotificationController::class, 'store'])->name('notifications.store');
    Route::post('notifications/settings', [NotificationController::class, 'settings'])->name('notifications.settings');
    Route::post('notifications/winback-settings', [NotificationController::class, 'winbackSettings'])->name('notifications.winback-settings');
    Route::post('notifications/run-new-arrivals', [NotificationController::class, 'runNewArrivals'])->name('notifications.run-new-arrivals');
    Route::post('notifications/run-winback', [NotificationController::class, 'runWinback'])->name('notifications.run-winback');
    Route::post('notifications/review-requests', [NotificationController::class, 'reviewRequestSettings'])->name('notifications.review-requests');
    Route::post('notifications/run-review-requests', [NotificationController::class, 'runReviewRequests'])->name('notifications.run-review-requests');
    Route::post('notifications/abandoned-sms', [NotificationController::class, 'abandonedSmsSettings'])->name('notifications.abandoned-sms');
    Route::post('notifications/run-abandoned-sms', [NotificationController::class, 'runAbandonedSms'])->name('notifications.run-abandoned-sms');
    Route::post('notifications/vapid-keys', [NotificationController::class, 'generateVapidKeys'])->name('notifications.vapid-keys');
    Route::post('notifications/test-push', [NotificationController::class, 'testPush'])->name('notifications.test-push');
    Route::post('notifications/push-templates', [NotificationController::class, 'savePushTemplates'])->name('notifications.push-templates');

    // Staff device alerts (new order → push on your phone)
    Route::post('push/subscribe', [AdminPushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('push/unsubscribe', [AdminPushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('push/test', [AdminPushController::class, 'test'])->name('push.test');
    Route::post('push/toggle', [AdminPushController::class, 'toggle'])->name('push.toggle');

    // Drip campaigns (scheduled push sequences)
    Route::get('drips', [DripController::class, 'index'])->name('drips.index');
    Route::post('drips', [DripController::class, 'store'])->name('drips.store');
    Route::put('drips/{drip}', [DripController::class, 'update'])->name('drips.update');
    Route::post('drips/{drip}/enroll', [DripController::class, 'enrollSegment'])->name('drips.enroll');
    Route::delete('drips/{drip}', [DripController::class, 'destroy'])->name('drips.destroy');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::get('customers/import', [CustomerController::class, 'importForm'])->name('customers.import');
    Route::post('customers/import', [CustomerController::class, 'import'])->name('customers.import.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::post('customers/{customer}/sms', [CustomerController::class, 'sendSms'])->name('customers.sms');
    Route::get('customers-export', [CustomerController::class, 'export'])->name('customers.export');
    Route::post('customers/{customer}/offers', [CustomerController::class, 'storeOffer'])->name('customers.offers.store');
    Route::put('customers/{customer}/offers/{offer}', [CustomerController::class, 'updateOffer'])->name('customers.offers.update');
    Route::post('customers/{customer}/offers/{offer}/toggle', [CustomerController::class, 'toggleOffer'])->name('customers.offers.toggle');
    Route::delete('customers/{customer}/offers/{offer}', [CustomerController::class, 'destroyOffer'])->name('customers.offers.destroy');
    Route::post('customers/{customer}/points', [CustomerController::class, 'adjustPoints'])->name('customers.points');
    Route::post('customers/bulk-offer', [CustomerController::class, 'bulkOffer'])->name('customers.bulk-offer');

    // Offers & promotions (auto-apply + PDP display)
    Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
    Route::post('offers', [OfferController::class, 'store'])->name('offers.store');
    Route::put('offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
    Route::delete('offers/{offer}', [OfferController::class, 'destroy'])->name('offers.destroy');
    Route::post('offers/register-discount', [OfferController::class, 'saveRegisterOffer'])->name('offers.register');
    Route::post('offers/loyalty', [OfferController::class, 'saveLoyalty'])->name('offers.loyalty');
    Route::post('offers/gift-ladder', [OfferController::class, 'saveGiftLadder'])->name('offers.gift-ladder');

    // Media library (browse / optimize / delete uploaded images & videos)
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::get('media/picker', [MediaController::class, 'picker'])->name('media.picker');
    Route::post('media/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::post('media/optimize', [MediaController::class, 'optimize'])->name('media.optimize');
    Route::post('media/convert', [MediaController::class, 'convert'])->name('media.convert');
    Route::post('media/watermark', [MediaController::class, 'watermark'])->name('media.watermark');
    Route::post('media/watermark-settings', [MediaController::class, 'watermarkSettings'])->name('media.watermark-settings');
    Route::post('media/watermark-preview', [MediaController::class, 'watermarkPreview'])->name('media.watermark-preview');
    Route::delete('media', [MediaController::class, 'destroy'])->name('media.destroy');

    // Suppliers & purchase orders (sourcing / procurement)
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit');
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus'])->name('purchase-orders.status');
    Route::get('purchase-orders/{purchaseOrder}/export', [PurchaseOrderController::class, 'export'])->name('purchase-orders.export');
    Route::post('purchase-orders-fetch-image', [PurchaseOrderController::class, 'fetchImage'])->name('purchase-orders.fetch-image');
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');

    // Reviews (moderation)
    Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::patch('reviews/{review}/status', [ReviewController::class, 'updateStatus'])->name('reviews.status');
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Abandoned carts (lead follow-up)
    Route::get('abandoned-carts', [AbandonedCartController::class, 'index'])->name('abandoned.index');
    Route::patch('abandoned-carts/{cart}/contacted', [AbandonedCartController::class, 'markContacted'])->name('abandoned.contacted');
    Route::delete('abandoned-carts/{cart}', [AbandonedCartController::class, 'destroy'])->name('abandoned.destroy');

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

    // Staff accounts & roles (admin only)
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // My profile (any admin user — change own name/email/password)
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

    // ── Meta Integration (Super Admin only) ────────────────────────────────
    // The unlock/security routes are reachable without the secondary password;
    // everything else sits behind the `meta.gate` wall.
    // Marketing Center hub (module cards from the registry).
    Route::get('marketing', [MarketingController::class, 'index'])->name('marketing.index');

    Route::prefix('meta')->name('meta.')->group(function () {
        // Security wall (reachable without the secondary password).
        Route::get('unlock', [MetaSecurityController::class, 'show'])->name('unlock');
        Route::post('unlock', [MetaSecurityController::class, 'unlock'])->name('unlock.submit');
        Route::post('security-password', [MetaSecurityController::class, 'updatePassword'])->name('password.update');
        Route::post('lock', [MetaSecurityController::class, 'lock'])->name('lock');

        // Single-product actions triggered from the product edit page — admin-only
        // but outside the password wall so they work inline (they only queue a
        // job, never expose credentials).
        Route::post('sync/{product}', [MetaIntegrationController::class, 'syncSingle'])->name('sync-single');
        Route::post('remove/{product}', [MetaIntegrationController::class, 'removeSingle'])->name('remove-single');

        // ── Meta Debug Mode (temporary) ────────────────────────────────────────
        // Outside the password wall so it can diagnose gate/OAuth issues; the
        // controller itself 404s unless Debug Mode is enabled (META_DEBUG=true /
        // local) and 403s without Meta access.
        Route::get('debug', [MetaDebugController::class, 'index'])->name('debug');
        Route::post('debug/test/{what}', [MetaDebugController::class, 'test'])->name('debug.test')->where('what', '[a-z_]+');
        Route::post('debug/clear', [MetaDebugController::class, 'clear'])->name('debug.clear');

        Route::middleware('meta.gate')->group(function () {
            // Meta Connection hub (Token Manager + modular per-module OAuth).
            Route::get('connection', [MetaConnectionController::class, 'index'])->name('connection');
            Route::get('connection/authorize/{module}', [MetaConnectionController::class, 'authorize'])->name('connection.authorize');
            // Note: no per-module callback route — every flow returns to the single
            // canonical callback `admin.meta.oauth.callback` (module is in `state`).
            Route::post('connection/disconnect', [MetaConnectionController::class, 'disconnect'])->name('connection.disconnect');

            Route::get('/', [MetaIntegrationController::class, 'index'])->name('index');
            Route::post('save', [MetaIntegrationController::class, 'save'])->name('save');
            Route::post('test', [MetaIntegrationController::class, 'testConnection'])->name('test');
            Route::post('mode', [MetaIntegrationController::class, 'switchMode'])->name('mode');
            Route::post('disconnect', [MetaIntegrationController::class, 'disconnect'])->name('disconnect');
            Route::post('refresh-catalog', [MetaIntegrationController::class, 'refreshCatalog'])->name('refresh-catalog');

            // Bulk sync actions.
            Route::post('sync-all', [MetaIntegrationController::class, 'syncAll'])->name('sync-all');
            Route::post('sync-refresh', [MetaIntegrationController::class, 'fullRefresh'])->name('sync-refresh');
            Route::post('sync-selected', [MetaIntegrationController::class, 'syncSelected'])->name('sync-selected');
            Route::get('batch-status', [MetaIntegrationController::class, 'batchStatus'])->name('batch-status');

            // Sync logs.
            Route::get('logs', [MetaSyncLogController::class, 'index'])->name('logs');
            Route::get('logs/export', [MetaSyncLogController::class, 'export'])->name('logs.export');
            Route::post('logs/retry-failed', [MetaSyncLogController::class, 'retryFailed'])->name('logs.retry');
            Route::post('logs/retry-selected', [MetaSyncLogController::class, 'retrySelected'])->name('logs.retry-selected');

            // Queue monitor.
            Route::get('queue', [MetaQueueController::class, 'index'])->name('queue');
            Route::get('queue/status', [MetaQueueController::class, 'status'])->name('queue.status');
            Route::post('queue/pause', [MetaQueueController::class, 'pause'])->name('queue.pause');
            Route::post('queue/resume', [MetaQueueController::class, 'resume'])->name('queue.resume');
            Route::post('queue/retry', [MetaQueueController::class, 'retry'])->name('queue.retry');

            // Webhook page.
            Route::get('webhook', [MetaIntegrationController::class, 'webhook'])->name('webhook');

            // Tracking dashboard (Pixel + Conversions API + Test panel + Diagnostics).
            Route::get('tracking', [MetaTrackingController::class, 'index'])->name('tracking');
            Route::post('tracking/save', [MetaTrackingController::class, 'save'])->name('tracking.save');
            Route::post('tracking/test/{event}', [MetaTrackingController::class, 'test'])->name('tracking.test')->where('event', '[A-Za-z]+');
            Route::get('tracking/diagnostics', [MetaTrackingController::class, 'diagnostics'])->name('tracking.diagnostics');
            Route::get('tracking/validate-token', [MetaTrackingController::class, 'validateToken'])->name('tracking.validate-token');

            // Production Mode OAuth ("Connect with Facebook").
            Route::get('oauth/redirect', [MetaOAuthController::class, 'redirect'])->name('oauth.redirect');
            Route::get('oauth/callback', [MetaOAuthController::class, 'callback'])->name('oauth.callback');
            Route::post('oauth/select-catalog', [MetaOAuthController::class, 'selectCatalog'])->name('oauth.select-catalog');
        });
    });

    // ── API tokens (admin API + MCP connector) ─────────────────────────────
    Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens');
    Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // ── System Configuration Manager (Super Admin only) ────────────────────
    Route::prefix('system-config')->name('system-config.')->group(function () {
        Route::get('/', [SystemConfigController::class, 'index'])->name('index');
        Route::get('audit', [SystemConfigController::class, 'audit'])->name('audit');

        // History.
        Route::get('history', [ConfigHistoryController::class, 'index'])->name('history');
        Route::get('history/compare', [ConfigHistoryController::class, 'compare'])->name('history.compare');
        Route::get('history/download', [ConfigHistoryController::class, 'download'])->name('history.download');
        Route::get('history/{version}', [ConfigHistoryController::class, 'show'])->name('history.show');

        // Backups + import/export.
        Route::get('backups', [ConfigBackupController::class, 'index'])->name('backups');
        Route::post('backups', [ConfigBackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backup}/restore', [ConfigBackupController::class, 'restorePreview'])->name('backups.restore-preview');
        Route::post('backups/{backup}/restore', [ConfigBackupController::class, 'restore'])->name('backups.restore');
        Route::get('export', [ConfigBackupController::class, 'export'])->name('export');
        Route::post('import/preview', [ConfigBackupController::class, 'importPreview'])->name('import.preview');
        Route::post('import', [ConfigBackupController::class, 'import'])->name('import');

        // Integrations (Steadfast courier, KhudeBarta SMS + templates, Google OAuth).
        // Consolidated here so every integration is configured in one place.
        Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
        Route::post('integrations', [IntegrationController::class, 'update'])->name('integrations.update');
        Route::post('integrations/test-sms', [IntegrationController::class, 'testSms'])->name('integrations.test-sms');

        // Section edit/save/test (catch-all — keep last).
        Route::get('{section}', [SystemConfigController::class, 'edit'])->name('edit')->where('section', '[a-z-]+');
        Route::post('{section}', [SystemConfigController::class, 'save'])->name('save')->where('section', '[a-z-]+');
        Route::post('{section}/test', [SystemConfigController::class, 'test'])->name('test')->where('section', '[a-z-]+');
    });

    // Content pages (footer legal pages) + contact-message inbox
    Route::get('pages', [PageController::class, 'edit'])->name('pages');
    Route::post('pages', [PageController::class, 'update'])->name('pages.update');
    Route::get('messages', [PageController::class, 'messages'])->name('messages');
    Route::post('messages/{message}/read', [PageController::class, 'markRead'])->name('messages.read');
    Route::delete('messages/{message}', [PageController::class, 'destroyMessage'])->name('messages.destroy');

    // Settings
    Route::get('settings', [SettingController::class, 'index'])->name('settings');
    Route::post('settings', [SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/mail', [SettingController::class, 'updateMail'])->name('settings.mail');
    Route::post('settings/mail/test', [SettingController::class, 'testMail'])->name('settings.mail.test');
});
