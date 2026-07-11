<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\FraudChecker\FraudCheckerSettings;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(SteadfastService $steadfast, SmsService $sms, FraudCheckerSettings $fraud)
    {
        return view('admin.settings', [
            'fraudChecker' => $fraud->formData(),
            'fraudConfigured' => $fraud->isConfigured(),
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
            'mail' => [
                'enabled' => (bool) Setting::get('mail_enabled', false),
                'host' => Setting::get('mail_host', ''),
                'port' => Setting::get('mail_port', '465'),
                'username' => Setting::get('mail_username', ''),
                'has_password' => filled(Setting::get('mail_password')),
                'encryption' => Setting::get('mail_encryption', 'ssl'),
                'from_address' => Setting::get('mail_from_address', ''),
                'from_name' => Setting::get('mail_from_name', Setting::get('store_name', config('store.name'))),
            ],
        ]);
    }

    /** Save the courier fraud-checker login credentials (DB, encrypted). */
    public function updateFraudChecker(Request $request, FraudCheckerSettings $fraud)
    {
        $data = $request->validate([
            'steadfast_user' => ['nullable', 'string', 'max:160'],
            'steadfast_password' => ['nullable', 'string', 'max:200'],
            'pathao_user' => ['nullable', 'string', 'max:160'],
            'pathao_password' => ['nullable', 'string', 'max:200'],
            'redx_phone' => ['nullable', 'string', 'max:40'],
            'redx_password' => ['nullable', 'string', 'max:200'],
        ]);

        $fraud->save($data);

        return back()->with('success', 'Fraud checker credentials saved.');
    }

    /** Save SMTP email settings (used to send order confirmations & invoices). */
    public function updateMail(Request $request)
    {
        $data = $request->validate([
            'mail_host' => ['nullable', 'string', 'max:160'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:160'],
            'mail_password' => ['nullable', 'string', 'max:200'],
            'mail_encryption' => ['nullable', 'in:ssl,tls,none'],
            'mail_from_address' => ['nullable', 'email', 'max:160'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        Setting::put('mail_enabled', $request->boolean('mail_enabled'));
        Setting::put('mail_host', $data['mail_host'] ?? null);
        Setting::put('mail_port', $data['mail_port'] ?? 465);
        Setting::put('mail_username', $data['mail_username'] ?? null);
        Setting::put('mail_encryption', $data['mail_encryption'] ?? 'ssl');
        Setting::put('mail_from_address', $data['mail_from_address'] ?? null);
        Setting::put('mail_from_name', $data['mail_from_name'] ?? null);

        // Only overwrite the stored password when a new one is typed in.
        if (filled($data['mail_password'] ?? null)) {
            Setting::put('mail_password', $data['mail_password']);
        }

        return back()->with('success', 'Email (SMTP) settings saved.');
    }

    /** Send a test email to confirm SMTP works. */
    public function testMail(Request $request)
    {
        $data = $request->validate(['test_email' => ['required', 'email']]);

        // Apply the just-saved DB settings to the live mailer for this request.
        app(\App\Services\MailConfigurator::class)->apply();

        try {
            \Illuminate\Support\Facades\Mail::raw(
                'This is a test email from your '.Setting::get('store_name', config('store.name')).' store. If you received this, SMTP is working. 🎉',
                fn ($m) => $m->to($data['test_email'])->subject('SMTP test — '.Setting::get('store_name', config('store.name')))
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Test failed: '.$e->getMessage());
        }

        return back()->with('success', 'Test email sent to '.$data['test_email'].'. Check the inbox (and spam).');
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
