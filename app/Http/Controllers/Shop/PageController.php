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

        return view('shop.page', [
            'title' => page_content($page, 'title'),
            'body' => page_content($page, 'body'),
        ]);
    }

    public function contact()
    {
        return view('shop.contact', [
            'title' => page_content('contact', 'title'),
            'intro' => page_content('contact', 'intro'),
        ]);
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
