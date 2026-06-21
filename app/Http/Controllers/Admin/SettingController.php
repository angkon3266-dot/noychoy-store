<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(SteadfastService $steadfast, SmsService $sms)
    {
        return view('admin.settings', [
            'general' => [
                'store_name' => Setting::get('store_name', config('store.name')),
                'store_phone' => Setting::get('store_phone', config('store.phone')),
                'store_email' => Setting::get('store_email', config('store.email')),
                'shipping_inside' => Setting::get('shipping_inside', config('store.shipping.inside_dhaka')),
                'shipping_outside' => Setting::get('shipping_outside', config('store.shipping.outside_dhaka')),
            ],
            'integrations' => [
                'steadfast_configured' => $steadfast->isConfigured(),
                'sms_enabled' => $sms->isEnabled(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'store_name' => ['nullable', 'string', 'max:120'],
            'store_phone' => ['nullable', 'string', 'max:40'],
            'store_email' => ['nullable', 'email', 'max:160'],
            'shipping_inside' => ['nullable', 'numeric', 'min:0'],
            'shipping_outside' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $value);
        }

        return back()->with('success', 'Settings saved.');
    }
}
