<?php

use App\Mcp\StoreServer;
use App\Http\Controllers\Api\V1\ProductApiController;
use App\Http\Controllers\Api\V1\ProductImageApiController;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Admin API + MCP
|--------------------------------------------------------------------------
| Token-authenticated, session-free routes for scripts and AI agents.
|
| Two doors into the same rooms: /api/v1/* for anything that speaks HTTP, and
| /mcp for clients that speak Model Context Protocol (Claude connects to a
| store this way — it is why Shopify works inside Claude and a plain REST API
| would not). Both delegate to App\Services\Api\CatalogApi.
|
| Throttled because image uploads download remote files and re-encode them:
| an agent in a loop should hit a limit, not the CPU ceiling of shared hosting.
*/

Route::middleware(['api.token:products', 'throttle:60,1'])->group(function () {
    Route::prefix('api/v1')->group(function () {
        Route::get('categories', [ProductApiController::class, 'categories']);
        Route::get('products', [ProductApiController::class, 'index']);
        Route::post('products', [ProductApiController::class, 'store']);
        Route::get('products/{product}', [ProductApiController::class, 'show']);
        Route::patch('products/{product}', [ProductApiController::class, 'update']);
        Route::delete('products/{product}', [ProductApiController::class, 'destroy']);

        Route::post('products/{product}/images', [ProductImageApiController::class, 'store']);
        Route::patch('products/{product}/images/{image}', [ProductImageApiController::class, 'update']);
        Route::delete('products/{product}/images/{image}', [ProductImageApiController::class, 'destroy']);
    });
});

// The Claude connector (App\Mcp\StoreServer). The token decides which admin
// user Claude acts as; each tool then checks that user's role. 120/min: a
// single "tidy up these 30 products" request is many quick calls.
Mcp::web('mcp', StoreServer::class)->middleware(['api.token', 'throttle:120,1']);
