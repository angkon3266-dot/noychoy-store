<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('customer.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $phone = preg_replace('/\D/', '', $data['phone']);
        $customer = Customer::where('phone', 'like', '%'.$phone.'%')->whereNotNull('password')->first();

        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages(['phone' => 'Invalid phone or password.']);
        }

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('account'));
    }

    public function showRegister()
    {
        return view('customer.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/', 'unique:customers,phone'],
            'email' => ['nullable', 'email', 'max:160', 'unique:customers,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'phone.regex' => 'Please enter a valid Bangladeshi mobile number (e.g. 01XXXXXXXXX).',
        ]);

        $customer = Customer::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
        ]);

        // Welcome loyalty bonus (Admin → Offers → Loyalty & points).
        $loyalty = app(\App\Services\LoyaltyService::class);
        if ($loyalty->enabled() && $loyalty->signupPoints() > 0) {
            $loyalty->award($customer, $loyalty->signupPoints(), 'signup', 'Welcome bonus');
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('account')->with('success', 'Welcome to Noychoy!');
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
