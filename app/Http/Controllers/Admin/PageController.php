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
                'about' => page_content('about'),
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
            'pages.about.title' => ['nullable', 'string', 'max:150'],
            'pages.about.body' => ['nullable', 'string'],
            // The story page's designed header.
            'pages.about.eyebrow' => ['nullable', 'string', 'max:60'],
            'pages.about.headline' => ['nullable', 'string', 'max:150'],
            'pages.about.lede' => ['nullable', 'string', 'max:400'],
            'pages.privacy.title' => ['nullable', 'string', 'max:150'],
            'pages.privacy.body' => ['nullable', 'string'],
            'pages.terms.title' => ['nullable', 'string', 'max:150'],
            'pages.terms.body' => ['nullable', 'string'],
            'pages.refund.title' => ['nullable', 'string', 'max:150'],
            'pages.refund.body' => ['nullable', 'string'],
            'pages.contact.title' => ['nullable', 'string', 'max:150'],
            'pages.contact.intro' => ['nullable', 'string', 'max:500'],
        ]);

        $pages = $data['pages'];

        // The header photo is posted by <x-media-field>, outside the pages[]
        // array, so it has to be folded back in — and carried over when nothing
        // new was chosen. Without this, saving any page on this form would
        // silently clear the story header's photo.
        $existingHero = page_content('about', 'hero_image');
        $pages['about']['hero_image'] = $request->boolean('about_hero_image_cleared')
            ? null
            : (resolve_media($request, 'about_hero_image', 'branding') ?? $existingHero);

        Setting::put('pages', $pages);

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
