<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Admin editor for the footer legal pages + the contact-message inbox.
 */
class PageController extends Controller
{
    public function edit()
    {
        return view('admin.pages.edit', [
            'pages' => [
                'privacy' => page_content('privacy'),
                'terms' => page_content('terms'),
                'refund' => page_content('refund'),
                'contact' => page_content('contact'),
            ],
            'unread' => ContactMessage::where('is_read', false)->count(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'pages' => ['required', 'array'],
            'pages.privacy.title' => ['nullable', 'string', 'max:150'],
            'pages.privacy.body' => ['nullable', 'string'],
            'pages.terms.title' => ['nullable', 'string', 'max:150'],
            'pages.terms.body' => ['nullable', 'string'],
            'pages.refund.title' => ['nullable', 'string', 'max:150'],
            'pages.refund.body' => ['nullable', 'string'],
            'pages.contact.title' => ['nullable', 'string', 'max:150'],
            'pages.contact.intro' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::put('pages', $data['pages']);

        return back()->with('success', 'Pages updated.');
    }

    // ── Contact-message inbox ──────────────────────────────────────────────

    public function messages()
    {
        return view('admin.pages.messages', [
            'messages' => ContactMessage::latest()->paginate(25),
        ]);
    }

    public function markRead(ContactMessage $message)
    {
        $message->update(['is_read' => ! $message->is_read]);

        return back();
    }

    public function destroyMessage(ContactMessage $message)
    {
        $message->delete();

        return back()->with('success', 'Message deleted.');
    }
}
