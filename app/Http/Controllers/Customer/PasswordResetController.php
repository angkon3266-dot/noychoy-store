<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Mail\CustomerPasswordResetMail;
use App\Models\Customer;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Password reset for customers via a one-time code (OTP) sent over SMS.
 * The OTP is held in the cache (10 min) — no extra table needed.
 */
class PasswordResetController extends Controller
{
    protected function cacheKey(string $phone): string
    {
        return 'pwotp:'.preg_replace('/\D/', '', $phone);
    }

    public function showForgot()
    {
        return view('customer.forgot-password');
    }

    public function sendOtp(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
        ], ['phone.regex' => 'Enter a valid Bangladeshi mobile number.']);

        $customer = Customer::where('phone', bd_phone($data['phone']))->first();

        // Always behave the same way to avoid leaking which numbers exist.
        if ($customer) {
            $otp = (string) random_int(100000, 999999);
            Cache::put($this->cacheKey($data['phone']), ['otp' => Hash::make($otp), 'tries' => 0], now()->addMinutes(10));

            // Editable template (Admin → Integrations → SMS templates → "Password reset code").
            $template = $sms->template('password_reset') ?: 'Your {store} password reset code is {code}. Valid for {minutes} minutes.';
            $message = strtr($template, [
                '{store}' => \App\Models\Setting::get('store_name', config('store.name')),
                '{code}' => $otp,
                '{minutes}' => '10',
            ]);
            $sms->send($customer->phone, $message);
        }

        return redirect()->route('customer.password.reset', ['phone' => $data['phone']])
            ->with('success', 'If that number has an account, we sent a 6-digit code by SMS.');
    }

    public function showReset(Request $request)
    {
        return view('customer.reset-password', ['phone' => $request->query('phone', '')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $key = $this->cacheKey($data['phone']);
        $record = Cache::get($key);

        if (! $record || ($record['tries'] ?? 0) >= 5) {
            Cache::forget($key);
            return back()->withInput()->with('error', 'Code expired or too many attempts. Please request a new code.');
        }

        if (! Hash::check($data['otp'], $record['otp'])) {
            $record['tries'] = ($record['tries'] ?? 0) + 1;
            Cache::put($key, $record, now()->addMinutes(10));
            return back()->withInput()->with('error', 'Incorrect code. Please try again.');
        }

        $customer = Customer::where('phone', bd_phone($data['phone']))->first();
        if (! $customer) {
            return back()->with('error', 'Account not found.');
        }

        $customer->update(['password' => $data['password']]); // hashed cast
        Cache::forget($key);
        auth('customer')->login($customer);

        return redirect()->route('account')->with('success', 'Password updated — you are now logged in.');
    }

    // ── Email link reset ────────────────────────────────────────────────────

    /** Email a one-time reset link (token held in cache for 60 min). */
    public function sendEmailLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $customer = Customer::where('email', $data['email'])->first();

        // Anti-enumeration: always respond the same.
        if ($customer) {
            $token = Str::random(64);
            Cache::put('pwreset:'.hash('sha256', $token), $customer->email, now()->addMinutes(60));
            $url = route('customer.password.email.form', ['token' => $token, 'email' => $customer->email]);
            try {
                Mail::to($customer->email)->send(new CustomerPasswordResetMail($customer, $url));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('success', 'If that email has an account, we sent a password reset link. Please check your inbox.');
    }

    public function showEmailReset(Request $request)
    {
        return view('customer.reset-password-email', [
            'token' => $request->query('token', ''),
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetViaEmail(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $cacheKey = 'pwreset:'.hash('sha256', $data['token']);
        $email = Cache::get($cacheKey);

        if (! $email || $email !== $data['email']) {
            return back()->withInput()->with('error', 'This reset link is invalid or has expired. Please request a new one.');
        }

        $customer = Customer::where('email', $email)->first();
        if (! $customer) {
            return back()->with('error', 'Account not found.');
        }

        $customer->update(['password' => $data['password']]);
        Cache::forget($cacheKey);
        auth('customer')->login($customer);

        return redirect()->route('account')->with('success', 'Password updated — you are now logged in.');
    }
}
