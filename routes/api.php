<?php

use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\V1\ProductApiController;
use App\Http\Controllers\Api\V1\ProductImageApiController;
use Illuminate\Support\Facades\Route;

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

Route::middleware(['api.token', 'throttle:60,1'])->group(function () {
    Route::prefix('api/v1')->group(function () {
        Route::get('products', [ProductApiController::class, 'index']);
        Route::get('products/{product}', [ProductApiController::class, 'show']);
        Route::patch('products/{product}', [ProductApiController::class, 'update']);

        Route::post('products/{product}/images', [ProductImageApiController::class, 'store']);
        Route::patch('products/{product}/images/{image}', [ProductImageApiController::class, 'update']);
        Route::delete('products/{product}/images/{image}', [ProductImageApiController::class, 'destroy']);
    });

    Route::post('mcp', [McpController::class, 'handle']);
});
