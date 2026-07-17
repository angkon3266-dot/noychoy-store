<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    /** Template keys exposed for editing in the admin. */
    public const TEMPLATE_KEYS = [
        'order_placed' => 'Order placed (sent immediately)',
        'order_confirmed' => 'Order confirmed',
        'order_shipped' => 'Order shipped',
        'order_delivered' => 'Order delivered / completed',
        'order_cancelled' => 'Order cancelled',
        'password_reset' => 'Password reset code — placeholders: {store}, {code}, {minutes}',
    ];

    public function index(SteadfastService $steadfast, SmsService $sms)
    {
        $int = Setting::get('integrations', []);
        $int = is_array($int) ? $int : [];

        $templates = [];
        foreach (self::TEMPLATE_KEYS as $key => $label) {
            $templates[$key] = $sms->template($key);
        }

        return view('admin.integrations', [
            'int' => $int,
            'templates' => $templates,
            'templateLabels' => self::TEMPLATE_KEYS,
            'steadfastOk' => $steadfast->isConfigured(),
            'smsOk' => $sms->isEnabled(),
            'webhookUrl' => route('steadfast.webhook'),
            'smsBalance' => $sms->isEnabled() ? $sms->getBalance() : [],
            'googleOk' => \App\Http\Controllers\Customer\GoogleController::isEnabled(),
            'googleRedirect' => route('customer.google.callback'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            // Steadfast
            'steadfast_base_url' => ['nullable', 'string', 'max:200'],
            'steadfast_api_key' => ['nullable', 'string', 'max:200'],
            'steadfast_secret_key' => ['nullable', 'string', 'max:200'],
            'steadfast_webhook_secret' => ['nullable', 'string', 'max:100'],
            // KhudeBarta SMS
            'sms_enabled' => ['nullable', 'boolean'],
            'sms_base_url' => ['nullable', 'string', 'max:200'],
            'sms_api_key' => ['nullable', 'string', 'max:200'],
            'sms_secret_key' => ['nullable', 'string', 'max:200'],
            'sms_caller_id' => ['nullable', 'string', 'max:40'],
            // Google OAuth
            'google_client_id' => ['nullable', 'string', 'max:200'],
            'google_client_secret' => ['nullable', 'string', 'max:200'],
            // Templates
            'templates' => ['nullable', 'array'],
            'templates.*' => ['nullable', 'string', 'max:600'],
        ]);

        $int = Setting::get('integrations', []);
        $int = is_array($int) ? $int : [];

        foreach (['steadfast_base_url', 'steadfast_api_key', 'steadfast_secret_key', 'steadfast_webhook_secret',
                  'sms_base_url', 'sms_api_key', 'sms_secret_key', 'sms_caller_id',
                  'google_client_id', 'google_client_secret'] as $key) {
            $int[$key] = $data[$key] ?? null;
        }
        $int['sms_enabled'] = $request->boolean('sms_enabled');

        Setting::put('integrations', $int);

        // Editable SMS templates.
        $templates = collect($data['templates'] ?? [])
            ->only(array_keys(self::TEMPLATE_KEYS))
            ->filter(fn ($v) => filled($v))
            ->all();
        Setting::put('sms_templates', $templates);

        return back()->with('success', 'Integrations & SMS templates saved.');
    }

    public function testSms(Request $request, SmsService $sms)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $ok = $sms->send($data['phone'], 'Test SMS from '.store_name().' — your SMS gateway is working.');

        return back()->with($ok ? 'success' : 'error',
            ($ok ? 'Test SMS sent.' : 'Test SMS failed.').$sms->explainLast());
    }
}
