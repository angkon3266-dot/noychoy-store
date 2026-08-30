<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

/**
 * Static storefront pages (about / privacy / terms / refund) + the contact page
 * & form. Content is editable in Admin → Pages (falls back to config/pages.php).
 */
class PageController extends Controller
{
    private const LEGAL = ['about', 'privacy', 'terms', 'refund'];

    /** Render an editable legal page. */
    public function legal(string $page)
    {
        abort_unless(in_array($page, self::LEGAL, true), 404);

        // The brand story is not a legal page. It is the one page a shopper
        // opens to decide whether the shop is real, so it gets a designed
        // layout rather than a column of body copy.
        if ($page === 'about') {
            return $this->story();
        }

        $title = page_content($page, 'title');

        return \Inertia\Inertia::render('Legal', [
            'pageTitle' => $title,
            'title' => $title,
            'body' => page_content($page, 'body'),
        ])->withViewData([
            'pageTitle' => $title,
            // The body is React-rendered, so without this the page reaches a
            // crawler as an empty div.
            'seoHeading' => $title,
            'seoIntro' => page_content($page, 'body'),
        ]);
    }

    /**
     * The brand story.
     *
     * The promise strip is read from the same `trust_badges` the footer and
     * product pages use, so the story page cannot promise something the rest of
     * the site has stopped offering.
     */
    protected function story()
    {
        $title = page_content('about', 'title');
        $headline = page_content('about', 'headline') ?: $title;
        $body = page_content('about', 'body');

        $promises = collect(theme('trust_badges'))
            ->filter(fn ($b) => filled($b['title'] ?? null))
            ->map(fn ($b) => [
                'icon' => $b['icon'] ?? null,
                'title' => (string) $b['title'],
                'text' => (string) ($b['text'] ?? ''),
            ])->values()->all();

        return \Inertia\Inertia::render('Story', [
            'pageTitle' => $title,
            'title' => $title,
            // The shop's own name is the right default here, and it keeps the
            // shared config out of the business of naming one brand.
            'eyebrow' => page_content('about', 'eyebrow') ?: store_name(),
            'headline' => $headline,
            'lede' => page_content('about', 'lede'),
            'heroImage' => theme_asset(page_content('about', 'hero_image')) ?: null,
            'body' => $body,
            'promises' => $promises,
            'shopUrl' => route('shop'),
            'contactUrl' => route('page.contact'),
        ])->withViewData([
            'pageTitle' => $title,
            // Without this the story reaches a crawler as an empty div — and
            // this is the page that answers "who are you?".
            'seoHeading' => $headline,
            'seoIntro' => trim((string) page_content('about', 'lede').' '.strip_tags($body)),
        ]);
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
        ])->withViewData([
            'pageTitle' => $title,
            'seoHeading' => $title,
            'seoIntro' => page_content('contact', 'intro'),
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
