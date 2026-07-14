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
            ],
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

    /** Run the batched new-arrivals announcement right now. */
    public function runNewArrivals()
    {
        \Illuminate\Support\Facades\Artisan::call('notifications:new-arrivals');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }
}
