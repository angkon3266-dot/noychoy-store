<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerNotification;
use App\Models\Setting;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        return view('admin.notifications.index', [
            'items' => CustomerNotification::with('segment')->orderByDesc('id')->paginate(20),
            'memberCount' => \App\Models\Customer::whereNotNull('password')->count(),
            'segments' => \App\Models\CustomerSegment::orderBy('name')->get(),
            'settings' => [
                'notify_new_arrivals' => (bool) Setting::get('notify_new_arrivals', true),
                'notify_preorders' => (bool) Setting::get('notify_preorders', true),
                'webpush_enabled' => (bool) Setting::get('webpush_enabled', false),
                'webpush_keys' => filled(Setting::get('webpush_public_key')),
                'webpush_subject' => Setting::get('webpush_subject', ''),
                'webpush_subscribers' => \App\Models\PushSubscription::whereNotNull('customer_id')->count(),
                // Win-back automation.
                'winback_enabled' => (bool) Setting::get('winback_enabled', false),
                'winback_days' => (int) Setting::get('winback_days', 60),
                'winback_cooldown_days' => (int) Setting::get('winback_cooldown_days', 30),
                'winback_offer_percent' => (float) Setting::get('winback_offer_percent', 10),
                'winback_offer_days' => (int) Setting::get('winback_offer_days', 14),
                'winback_title' => Setting::get('winback_title', 'We miss you 💛'),
                'winback_body' => Setting::get('winback_body', 'It’s been a while — here’s a little something to welcome you back.'),
                'winback_sms' => (bool) Setting::get('winback_sms', false),
            ],
            'winbackDue' => \App\Models\Customer::whereNotNull('password')->where('blacklisted', false)
                ->where('total_orders', '>', 0)
                ->where('last_order_at', '<', now()->subDays((int) Setting::get('winback_days', 60)))
                ->where(fn ($w) => $w->whereNull('winback_sent_at')->orWhere('winback_sent_at', '<', now()->subDays((int) Setting::get('winback_cooldown_days', 30))))
                ->count(),
            'analytics' => app(\App\Services\CampaignAnalyticsService::class),
            'pushTemplates' => collect(\App\Services\PushTemplateService::defaults())->map(fn ($d, $key) => [
                'label' => $d['label'],
                'enabled' => (bool) Setting::get("push_tpl_{$key}_enabled", true),
                'title' => Setting::get("push_tpl_{$key}_title", $d['title']),
                'body' => Setting::get("push_tpl_{$key}_body", $d['body']),
            ])->all(),
        ]);
    }

    /** Save the editable transactional push templates (order updates, etc.). */
    public function savePushTemplates(Request $request)
    {
        foreach (array_keys(\App\Services\PushTemplateService::defaults()) as $key) {
            Setting::put("push_tpl_{$key}_enabled", $request->boolean("enabled_{$key}"));
            Setting::put("push_tpl_{$key}_title", trim((string) $request->input("title_{$key}")));
            Setting::put("push_tpl_{$key}_body", trim((string) $request->input("body_{$key}")));
        }

        return back()->with('success', 'Automated push templates saved.');
    }

    /** Compose + send (or schedule) a notification to all members or a segment. */
    public function store(Request $request, NotificationService $notifications, \App\Services\SegmentService $segmentSvc)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:255'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'icon' => ['nullable', 'string', 'max:16'],
            'audience' => ['required', 'in:all,segment'],
            'segment_id' => ['nullable', 'required_if:audience,segment', 'exists:customer_segments,id'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Resolve recipients for a segment send (snapshot at send time).
        $recipientIds = null;
        $phones = [];
        $segmentId = null;
        if ($data['audience'] === 'segment') {
            $segment = \App\Models\CustomerSegment::findOrFail($data['segment_id']);
            $recipients = $segmentSvc->query($segment)->get(['customers.id', 'customers.phone']);
            $recipientIds = $recipients->pluck('id')->all();
            $phones = $recipients->pluck('phone')->filter()->values()->all();
            $segmentId = $segment->id;
        }

        $notification = $notifications->broadcast([
            'type' => 'announcement',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'icon' => $data['icon'] ?? null,
            'segment_id' => $segmentId,
            'recipient_ids' => $recipientIds,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);

        // Optional SMS (immediate sends only) — queued so a big list doesn't block.
        $smsQueued = 0;
        if ($request->boolean('send_sms') && $notification->sent_at) {
            $targetPhones = $data['audience'] === 'segment'
                ? $phones
                : \App\Models\Customer::whereNotNull('password')->whereNotNull('phone')->pluck('phone')->all();
            $text = trim($data['title'].($data['body'] ? "\n".$data['body'] : ''));
            foreach (array_chunk($targetPhones, 100) as $chunk) {
                \App\Jobs\SendSegmentSms::dispatch($chunk, $text);
                $smsQueued += count($chunk);
            }
        }

        $msg = ! empty($data['scheduled_at']) ? 'Notification scheduled.' : 'Notification sent.';
        if ($smsQueued > 0) {
            $msg .= " SMS queued to {$smsQueued} member(s).";
        }

        return back()->with('success', $msg);
    }

    public function destroy(CustomerNotification $notification)
    {
        $notification->delete();

        return back()->with('success', 'Notification removed.');
    }

    /** Save the auto-trigger + web-push toggles. */
    public function settings(Request $request)
    {
        Setting::put('notify_new_arrivals', $request->boolean('notify_new_arrivals'));
        Setting::put('notify_preorders', $request->boolean('notify_preorders'));
        Setting::put('webpush_enabled', $request->boolean('webpush_enabled'));
        Setting::put('webpush_subject', trim((string) $request->input('webpush_subject')));

        return back()->with('success', 'Notification settings saved.');
    }

    /** Generate (or replace) the VAPID keypair used to sign web-push messages. */
    public function generateVapidKeys(Request $request, \App\Services\WebPushService $push)
    {
        $force = filled(Setting::get('webpush_public_key'));
        try {
            $keys = $push->generateKeys();
        } catch (\Throwable $e) {
            return back()->with('error', 'Key generation failed: '.$e->getMessage());
        }
        Setting::put('webpush_public_key', $keys['public']);
        Setting::put('webpush_private_key', $keys['private']);

        return back()->with('success', $force
            ? 'New VAPID keys generated. Existing subscribers must re-enable notifications.'
            : 'VAPID keys generated. You can now enable web push.');
    }

    /** Send a test push to all current subscribers (synchronously, for instant feedback). */
    public function testPush(\App\Services\WebPushService $push)
    {
        if (! $push->ready()) {
            return back()->with('error', 'Web push isn’t ready — enable it and generate VAPID keys first.');
        }

        $subs = \App\Models\PushSubscription::whereNotNull('customer_id')->get();
        if ($subs->isEmpty()) {
            return back()->with('error', 'No subscribers yet. Open the storefront as a logged-in member and turn on notifications first.');
        }

        $payload = [
            'title' => '🔔 Test notification',
            'body' => 'Web push is working — this is a test from your admin.',
            'url' => route('shop'),
            'icon' => theme_asset(theme('logo')) ?: asset('favicon.ico'),
            'tag' => 'test-'.now()->timestamp,
        ];

        $ok = 0;
        $gone = 0;
        foreach ($subs as $sub) {
            $status = $push->send($sub, $payload);
            if ($status >= 200 && $status < 300) {
                $ok++;
            } elseif (in_array($status, [404, 410], true)) {
                $sub->delete();
                $gone++;
            }
        }

        return back()->with('success', "Test push sent: {$ok} delivered".($gone ? ", {$gone} stale subscription(s) removed" : '').'.');
    }

    /** Save the win-back automation settings. */
    public function winbackSettings(Request $request)
    {
        $data = $request->validate([
            'winback_days' => ['required', 'integer', 'min:7', 'max:365'],
            'winback_cooldown_days' => ['required', 'integer', 'min:7', 'max:365'],
            'winback_offer_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'winback_offer_days' => ['required', 'integer', 'min:1', 'max:90'],
            'winback_title' => ['required', 'string', 'max:120'],
            'winback_body' => ['nullable', 'string', 'max:400'],
        ]);

        Setting::put('winback_enabled', $request->boolean('winback_enabled'));
        Setting::put('winback_days', $data['winback_days']);
        Setting::put('winback_cooldown_days', $data['winback_cooldown_days']);
        Setting::put('winback_offer_percent', $data['winback_offer_percent'] ?? 0);
        Setting::put('winback_offer_days', $data['winback_offer_days']);
        Setting::put('winback_title', $data['winback_title']);
        Setting::put('winback_body', $data['winback_body'] ?? '');
        Setting::put('winback_sms', $request->boolean('winback_sms'));

        return back()->with('success', 'Win-back settings saved.');
    }

    /** Run the batched new-arrivals announcement right now. */
    public function runNewArrivals()
    {
        \Illuminate\Support\Facades\Artisan::call('notifications:new-arrivals');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }

    /** Run the win-back automation right now. */
    public function runWinback()
    {
        \Illuminate\Support\Facades\Artisan::call('crm:winback');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }
}
