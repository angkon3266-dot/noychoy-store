<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Google" for customers — implemented directly against Google's
 * OAuth2 endpoints (no Socialite dependency, friendlier to shared hosting).
 * Credentials live in config/services.php → env GOOGLE_CLIENT_ID/SECRET/REDIRECT.
 */
class GoogleController extends Controller
{
    protected function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    public function redirect(Request $request)
    {
        if (! $this->configured()) {
            return redirect()->route('customer.login')
                ->with('error', 'Google login is not configured yet.');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $params = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$params);
    }

    public function callback(Request $request)
    {
        if (! $this->configured()) {
            return redirect()->route('customer.login')->with('error', 'Google login is not configured.');
        }

        // CSRF protection on the OAuth round-trip.
        if (! $request->filled('state') || $request->get('state') !== $request->session()->pull('google_oauth_state')) {
            return redirect()->route('customer.login')->with('error', 'Google login expired. Please try again.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('customer.login')->with('error', 'Google login was cancelled.');
        }

        // Exchange the authorization code for tokens.
        $tokenResp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->get('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $tokenResp->ok() || ! $tokenResp->json('access_token')) {
            return redirect()->route('customer.login')->with('error', 'Could not verify your Google account. Please try again.');
        }

        // Fetch the user's basic profile.
        $profile = Http::withToken($tokenResp->json('access_token'))
            ->get('https://www.googleapis.com/oauth2/v3/userinfo')->json();

        $email = $profile['email'] ?? null;
        $googleId = $profile['sub'] ?? null;
        if (! $email || ! $googleId) {
            return redirect()->route('customer.login')->with('error', 'Google did not return an email address.');
        }

        // Match by google_id, then by email; otherwise create a new account.
        $customer = Customer::where('google_id', $googleId)->first()
            ?? Customer::where('email', $email)->first();

        if ($customer) {
            $customer->forceFill([
                'google_id' => $googleId,
                'avatar' => $profile['picture'] ?? $customer->avatar,
                'name' => $customer->name ?: ($profile['name'] ?? 'Customer'),
            ])->save();
        } else {
            $customer = Customer::create([
                'name' => $profile['name'] ?? 'Customer',
                'email' => $email,
                'google_id' => $googleId,
                'avatar' => $profile['picture'] ?? null,
            ]);
        }

        Auth::guard('customer')->login($customer, true);
        $request->session()->regenerate();

        return redirect()->intended(route('account'))->with('success', 'Signed in with Google.');
    }
}
