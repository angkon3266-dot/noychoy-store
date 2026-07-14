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
            'items' => CustomerNotification::orderByDesc('id')->paginate(20),
            'memberCount' => \App\Models\Customer::whereNotNull('password')->count(),
            'settings' => [
                'notify_new_arrivals' => (bool) Setting::get('notify_new_arrivals', true),
                'notify_preorders' => (bool) Setting::get('notify_preorders', true),
                'webpush_enabled' => (bool) Setting::get('webpush_enabled', false),
            ],
        ]);
    }

    /** Compose + send (or schedule) a broadcast to members. */
    public function store(Request $request, NotificationService $notifications)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:255'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'icon' => ['nullable', 'string', 'max:16'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $notifications->broadcast([
            'type' => 'announcement',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'icon' => $data['icon'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);

        return back()->with('success', ! empty($data['scheduled_at'])
            ? 'Notification scheduled.'
            : 'Notification sent to members.');
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
