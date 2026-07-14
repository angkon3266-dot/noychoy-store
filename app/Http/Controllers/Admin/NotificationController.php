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
        ]);
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

        return back()->with('success', 'Notification settings saved.');
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
