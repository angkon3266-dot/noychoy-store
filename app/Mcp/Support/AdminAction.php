<?php

namespace App\Mcp\Support;

use App\Mcp\ToolError;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Run an admin-panel controller action on Claude's behalf.
 *
 * Coupons, offers, categories, collections, reviews and the menu all have
 * validation and side effects (phone lists, timezone conversion, points for an
 * approved review, menu sanitising) that live in their admin controllers. A
 * second copy of those rules for the connector would drift, so the tools post
 * to the same controller method the admin form does, as the token's user, and
 * read back the flash message the page would have shown.
 *
 * The call gets its own throwaway request and in-memory session; nothing is
 * written to the real session store and the outer request is restored after.
 * Validation failures surface as the ValidationException they already are.
 */
class AdminAction
{
    /**
     * @param  class-string  $controller
     * @param  array<string,mixed>  $input  the form body
     * @param  array<string,mixed>  $params  route-model parameters, e.g. ['coupon' => $coupon]
     * @return string the success message the admin page would have flashed
     */
    public static function run(string $controller, string $method, array $input, array $params = []): string
    {
        $app = app();
        $user = Auth::user();

        $request = Request::create('/admin/_connector', 'POST', $input);
        $session = new Store('connector', new ArraySessionHandler(5));
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        $outer = $app->make('request');
        $redirector = $app->make('redirect');

        $app->instance('request', $request);
        $redirector->setSession($session);

        try {
            $app->call([$app->make($controller), $method], ['request' => $request] + $params);
        } finally {
            $app->instance('request', $outer);
            $redirector->setSession($app->make('session.store'));
        }

        $errors = $session->get('errors');
        if ($errors instanceof ViewErrorBag || $errors instanceof MessageBag) {
            $messages = $errors instanceof ViewErrorBag ? $errors->getBag('default')->all() : $errors->all();
            if ($messages !== []) {
                throw new ToolError(implode(' ', $messages));
            }
        }

        if (filled($error = $session->get('error'))) {
            throw new ToolError(strip_tags((string) $error));
        }

        return strip_tags((string) ($session->get('success') ?? $session->get('warning') ?? 'Done.'));
    }
}
