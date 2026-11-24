<?php

use App\Http\Controllers\OAuth\OAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OAuth for the Claude connector
|--------------------------------------------------------------------------
| How claude.ai's "custom connector" signs in to this store. See
| OAuthController for which spec each route answers.
|
| Discovery, registration and the token endpoint are machine-to-machine:
| no session, no CSRF (registered outside the `web` group, like /mcp). The
| consent screen is the one human step, so it runs in `web` and reuses the
| admin login.
|
| The route names `mcp.oauth.protected-resource*` matter: laravel/mcp's
| WWW-Authenticate middleware looks for them to point a 401 from /mcp here.
*/

Route::get('.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource'])
    ->name('mcp.oauth.protected-resource');
Route::get('.well-known/oauth-protected-resource/{path}', [OAuthController::class, 'protectedResource'])
    ->where('path', '.*')->name('mcp.oauth.protected-resource.nested');

Route::get('.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer'])
    ->name('mcp.oauth.authorization-server');
Route::get('.well-known/oauth-authorization-server/{path}', [OAuthController::class, 'authorizationServer'])
    ->where('path', '.*')->name('mcp.oauth.authorization-server.nested');

Route::post('oauth/register', [OAuthController::class, 'register'])
    ->middleware('throttle:10,1')->name('oauth.register');
Route::post('oauth/token', [OAuthController::class, 'token'])
    ->middleware('throttle:30,1')->name('oauth.token');
Route::options('oauth/{any}', [OAuthController::class, 'preflight'])->where('any', 'register|token');

Route::middleware('web')->group(function () {
    Route::get('oauth/authorize', [OAuthController::class, 'showAuthorize'])->name('oauth.authorize');
    Route::post('oauth/authorize', [OAuthController::class, 'approve'])
        ->middleware('throttle:20,1')->name('oauth.approve');
});
