<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

/**
 * Static storefront pages (privacy / terms / refund) + the contact page & form.
 * Legal content is editable in Admin → Pages (falls back to config/pages.php).
 */
class PageController extends Controller
{
    private const LEGAL = ['privacy', 'terms', 'refund'];

    /** Render an editable legal page. */
    public function legal(string $page)
    {
        abort_unless(in_array($page, self::LEGAL, true), 404);

        $title = page_content($page, 'title');

        return \Inertia\Inertia::render('Legal', [
            'pageTitle' => $title,
            'title' => $title,
            'body' => page_content($page, 'body'),
        ])->withViewData(['pageTitle' => $title]);
    }

    public function contact()
    {
        $title = page_content('contact', 'title');

        return \Inertia\Inertia::render('Contact', [
            'pageTitle' => $title,
            'title' => $title,
            'intro' => page_content('contact', 'intro'),
            'details' => [
                'phone' => \App\Models\Setting::get('store_phone', config('store.phone')),
                'email' => \App\Models\Setting::get('store_email', config('store.email')),
                'address' => \App\Models\Setting::get('store_address', config('store.address')),
                'whatsapp' => theme('whatsapp_number'),
            ],
            'submitUrl' => route('page.contact.submit'),
        ])->withViewData(['pageTitle' => $title]);
    }

    public function submitContact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        ContactMessage::create($data + ['ip' => $request->ip()]);

        return back()->with('success', 'Thanks for reaching out! We\'ll get back to you soon.');
    }
}
