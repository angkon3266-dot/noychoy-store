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
            // Live coupons the admin can attach to a push as a real offer.
            //
            // Codes reserved to one buyer are excluded. They are auto-minted
            // and therefore the newest rows in the table, so they would sit at
            // the top of this picker: one wrong click would broadcast a
            // private code to every subscriber, and every one of them would be
            // turned away at checkout by the reservation.
            'coupons' => \App\Models\Coupon::where('is_active', true)
                ->whereNull('reserved_for_phone')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
                ->orderByDesc('id')->get(),
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
                // Post-delivery review requests. Off by default — this spends
                // the owner's SMS credit, so it is theirs to switch on.
                'review_request_enabled' => (bool) Setting::get('review_request_enabled', false),
                'review_request_delay_days' => (int) Setting::get('review_request_delay_days', 3),
                'review_request_max_days' => (int) Setting::get('review_request_max_days', 30),
                'review_request_per_run' => (int) Setting::get('review_request_per_run', 100),
                'review_request_email_subject' => Setting::get('review_request_email_subject', ''),
                'review_request_email_body' => Setting::get('review_request_email_body', ''),
                // The thank-you discount that rides along with the request.
                'review_offer_enabled' => (bool) Setting::get('review_offer_enabled', false),
                'review_offer_percent' => (float) Setting::get('review_offer_percent', 10),
                'review_offer_days' => (int) Setting::get('review_offer_days', 30),
                // Abandoned-cart SMS. Off by default — it spends SMS credit.
                'abandoned_sms_enabled' => (bool) Setting::get('abandoned_sms_enabled', false),
                'abandoned_sms_delay_minutes' => (int) Setting::get('abandoned_sms_delay_minutes', 60),
                'abandoned_sms_max_hours' => (int) Setting::get('abandoned_sms_max_hours', 48),
                'abandoned_sms_per_run' => (int) Setting::get('abandoned_sms_per_run', 50),
            ],
            'winbackDue' => \App\Models\Customer::whereNotNull('password')->where('blacklisted', false)
                ->where('total_orders', '>', 0)
                ->where('last_order_at', '<', now()->subDays((int) Setting::get('winback_days', 60)))
                ->where(fn ($w) => $w->whereNull('winback_sent_at')->orWhere('winback_sent_at', '<', now()->subDays((int) Setting::get('winback_cooldown_days', 30))))
                ->count(),
            // Same query the command runs, so the number on the card is the
            // number that would actually be asked.
            'reviewRequestDue' => \App\Console\Commands\RunReviewRequests::dueQuery(
                max(1, (int) Setting::get('review_request_delay_days', 3)),
                max(2, (int) Setting::get('review_request_max_days', 30)),
            )->count(),
            'abandonedSmsDue' => \App\Console\Commands\RunAbandonedCartSms::dueQuery(
                max(15, (int) Setting::get('abandoned_sms_delay_minutes', 60)),
                max(2, (int) Setting::get('abandoned_sms_max_hours', 48)),
            )->count(),
            'analytics' => app(\App\Services\CampaignAnalyticsService::class),
            'webpushDiag' => app(\App\Services\WebPushService::class)->diagnostics(),
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
            'image' => ['nullable', 'string', 'max:500'],
            'actions' => ['nullable', 'array', 'max:2'],
            'actions.*.label' => ['nullable', 'string', 'max:30'],
            'actions.*.url' => ['nullable', 'string', 'max:255'],
            'audience' => ['required', 'in:all,segment'],
            'segment_id' => ['nullable', 'required_if:audience,segment', 'exists:customer_segments,id'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Attach a real offer: embed the coupon code in the message + point the
        // push at the shop so recipients can redeem it.
        if (filled($data['coupon_code'] ?? null)) {
            $code = strtoupper(trim($data['coupon_code']));
            $data['body'] = trim(($data['body'] ?? '')."\n🎟 Use code ".$code." at checkout.");
            $data['url'] = ($data['url'] ?? null) ?: route('shop');
            $data['cta_label'] = ($data['cta_label'] ?? null) ?: 'Shop the offer';
        }

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

        // Keep only fully-filled action buttons (label + url).
        $actions = collect($data['actions'] ?? [])
            ->filter(fn ($a) => filled($a['label'] ?? null) && filled($a['url'] ?? null))
            ->map(fn ($a) => ['label' => $a['label'], 'url' => $a['url']])
            ->values()->all();

        $notification = $notifications->broadcast([
            'type' => 'announcement',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'icon' => $data['icon'] ?? null,
            'image' => $data['image'] ?? null,
            'actions' => $actions,
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

        // Be honest about browser push — "sent" while push silently skipped is
        // exactly the confusion we want to avoid.
        if ($notification->sent_at) {
            $pushSvc = app(\App\Services\WebPushService::class);
            if (! $pushSvc->ready()) {
                return back()->with('success', $msg)
                    ->with('warning', 'Browser push was NOT fired — web push is disabled or VAPID keys are missing. Fix it in “Web push” settings below, then send again.');
            }
            if ($notifications->lastPushQueued === 0) {
                return back()->with('success', $msg)
                    ->with('warning', 'Browser push was NOT fired — nobody is subscribed yet. Members must tap the bell on the storefront and press “Turn on” (on iPhone: install via Share → Add to Home Screen first).');
            }
            $msg .= " Browser push queued to {$notifications->lastPushQueued} device(s) — delivers within a minute.";
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
        $failures = [];
        foreach ($subs as $sub) {
            $status = $push->send($sub, $payload);
            if ($status >= 200 && $status < 300) {
                $ok++;
            } elseif ($push->shouldPrune($status)) {
                // Gone, or subscribed under an old VAPID key — remove it so the
                // member re-subscribes cleanly next time they open the site.
                $sub->delete();
                $gone++;
            } else {
                // Capture the first real failure to show the admin exactly why.
                $failures[] = 'HTTP '.$status.($push->lastResult['error'] ? ' ('.$push->lastResult['error'].')' : '')
                    .($push->lastResult['body'] ? ' — '.$push->lastResult['body'] : '');
            }
        }

        if ($ok > 0) {
            return back()->with('success', "Test push sent: {$ok} delivered".($gone ? ", {$gone} stale subscription(s) removed" : '').'.');
        }

        if ($gone > 0 && ! $failures) {
            // The classic "keys were regenerated after people subscribed" case.
            return back()->with('error', "Removed {$gone} subscription(s) that were made under an older key. Ask members to reopen the storefront (they'll re-subscribe automatically), then test again.");
        }

        $why = $failures ? ' First error: '.$failures[0] : '';
        return back()->with('error', "Test push delivered to 0 of {$subs->count()}.".$why);
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

    /** Save the post-delivery review-request settings. */
    public function reviewRequestSettings(Request $request)
    {
        $data = $request->validate([
            'review_request_delay_days' => ['required', 'integer', 'min:1', 'max:60'],
            'review_request_max_days' => ['required', 'integer', 'min:2', 'max:365'],
            'review_request_per_run' => ['required', 'integer', 'min:1', 'max:500'],
            'review_request_email_subject' => ['nullable', 'string', 'max:150'],
            'review_request_email_body' => ['nullable', 'string', 'max:400'],
            // Optional so a form posted without them (or an older one) keeps
            // whatever is already set rather than failing validation.
            'review_offer_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'review_offer_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        Setting::put('review_request_enabled', $request->boolean('review_request_enabled'));
        Setting::put('review_request_delay_days', $data['review_request_delay_days']);
        Setting::put('review_request_max_days', $data['review_request_max_days']);
        Setting::put('review_request_per_run', $data['review_request_per_run']);
        Setting::put('review_request_email_subject', $data['review_request_email_subject'] ?? '');
        Setting::put('review_request_email_body', $data['review_request_email_body'] ?? '');
        Setting::put('review_offer_enabled', $request->boolean('review_offer_enabled'));
        Setting::put('review_offer_percent', (float) ($data['review_offer_percent'] ?? Setting::get('review_offer_percent', 10)));
        Setting::put('review_offer_days', (int) ($data['review_offer_days'] ?? Setting::get('review_offer_days', 30)));

        return back()->with('success', 'Review-request settings saved.');
    }

    /** Save the abandoned-cart SMS settings. */
    public function abandonedSmsSettings(Request $request)
    {
        $data = $request->validate([
            'abandoned_sms_delay_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'abandoned_sms_max_hours' => ['required', 'integer', 'min:2', 'max:720'],
            'abandoned_sms_per_run' => ['required', 'integer', 'min:1', 'max:300'],
        ]);

        Setting::put('abandoned_sms_enabled', $request->boolean('abandoned_sms_enabled'));
        Setting::put('abandoned_sms_delay_minutes', $data['abandoned_sms_delay_minutes']);
        Setting::put('abandoned_sms_max_hours', $data['abandoned_sms_max_hours']);
        Setting::put('abandoned_sms_per_run', $data['abandoned_sms_per_run']);

        return back()->with('success', 'Abandoned-cart SMS settings saved.');
    }

    /** Send the abandoned-cart reminders right now. */
    public function runAbandonedSms()
    {
        \Illuminate\Support\Facades\Artisan::call('sms:abandoned-cart');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }

    /** Run the post-delivery review requests right now. */
    public function runReviewRequests()
    {
        \Illuminate\Support\Facades\Artisan::call('reviews:request');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }
}
