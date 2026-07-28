<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * _fbp and _fbc are written by Meta's pixel.js in the browser, unencrypted.
 * Laravel's EncryptCookies middleware decrypts every cookie in the web group
 * and nulls the ones it can't — so without an exemption the server reads null
 * and CAPI sends no fbp/fbc at all.
 */
class MetaCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_cookies_reach_the_server_unmangled(): void
    {
        Route::middleware('web')->get('/_test/cookies', fn (Request $r) => response()->json([
            'fbp' => $r->cookie('_fbp'),
            'fbc' => $r->cookie('_fbc'),
        ]));

        $this->withUnencryptedCookies([
            '_fbp' => 'fb.1.1700000000000.1234567890',
            '_fbc' => 'fb.1.1700000000000.IwAR0abcdef',
        ])->get('/_test/cookies')->assertJson([
            'fbp' => 'fb.1.1700000000000.1234567890',
            'fbc' => 'fb.1.1700000000000.IwAR0abcdef',
        ]);
    }
}
