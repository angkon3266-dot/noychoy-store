<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') — {{ store_name() }} Admin</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if($fav = theme_asset(theme('favicon')))<link rel="icon" href="{{ $fav }}">@else<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any"><link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">@endif
    {{-- Installable admin app — iOS only delivers push to a Home-Screen install. --}}
    <link rel="manifest" href="{{ url('/site.webmanifest?admin=1') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ store_name() }} Admin">
    @if($appleIcon = theme_asset(theme('favicon')))
        <link rel="apple-touch-icon" href="{{ $appleIcon }}">
    @endif
    {{-- Sidebar state, applied before the first paint.

         The owner, 2026-09-17: "The side bar in admin panel — add option to
         collapse and show it — right now when it's open I have to press
         something to close it." On a desktop the sidebar now folds down to an
         icon rail, and that choice is remembered per browser. Reading it has
         to happen here, in <head>, synchronously and before the stylesheet:
         Alpine boots from a deferred module, so a choice restored by Alpine
         would paint the full sidebar first and snap it shut on every page load.

         `sb-js` says JavaScript is running, so the controls that need it can
         hide when it isn't — without JS the sidebar is simply open, as it
         always was. localStorage sits inside a try because it THROWS outright
         when site data is blocked; `sb-js` is set before the read so that
         browser still gets working toggles, just with nothing remembered. --}}
    <script>
        (function (root) {
            root.classList.add('sb-js');
            try {
                if (localStorage.getItem('adminSidebarCollapsed') === '1') root.classList.add('sb-collapsed');
            } catch (e) {}
        })(document.documentElement);
    </script>
    {{-- The drawer and the rail are plain CSS keyed off two classes — `sb-collapsed`
         on <html> (set by the script above, long before Alpine exists) and
         `sb-open` on <body> (Alpine's phone drawer) — rather than Tailwind
         classes swapped by Alpine. Two reasons. A stylesheet in the view cannot
         be left behind by a forgotten `npm run build`, so the fold works on the
         first deploy. And these rules are unlayered, so they beat Tailwind's
         utilities (which live in @layer utilities) outright: no `md:ml-60`
         against `ml-16` ordering to get wrong, and no !important. The
         breakpoints are written the way Tailwind v4 writes `md`, so the rail
         and `md:ml-60` switch at the same pixel.

         [x-cloak] lives here too, not at the foot of <body>: a browser paints
         while it parses, and a rule that arrives after the page has been
         painted lets every cloaked panel flash first. --}}
    <style>
        [x-cloak] { display: none !important; }

        /* Only worth showing when Alpine is there to answer the click. */
        html:not(.sb-js) .sb-needs-js { display: none; }

        /* Only the folded rail shows these. */
        .sb-mark, .sb-foot-icon { display: none; }
        .sb-backdrop { display: none; }

        /* The bell's panel is 22rem, wider than a phone once the gutters are in. */
        .sb-pop { max-width: calc(100vw - 2rem); }

        /* Phone: the sidebar is a drawer over the page. */
        @media (width < 48rem) {
            /* Closed from the first paint, so a phone no longer sees the drawer
               cover the page and then slide away on every load. `visibility`
               also takes the off-screen links out of the Tab order; its change
               waits for the slide to finish on the way out. Gated on sb-js:
               with JavaScript off the drawer stays where it always was, or the
               menu would be unreachable. */
            html.sb-js .sb-aside {
                translate: -100% 0;
                visibility: hidden;
                transition: translate 200ms cubic-bezier(.4, 0, .2, 1), visibility 0s linear 200ms;
            }
            html.sb-js body.sb-open .sb-aside {
                translate: none;
                visibility: visible;
                transition: translate 200ms cubic-bezier(.4, 0, .2, 1);
            }

            /* Tapping anywhere off the open drawer closes it. */
            .sb-backdrop {
                display: block;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                background-color: rgb(22 22 24 / .55);
                transition: opacity 200ms ease, visibility 0s linear 200ms;
            }
            body.sb-open .sb-backdrop {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
                transition: opacity 200ms ease;
            }

            /* The page behind an open drawer stays put. */
            body.sb-open { overflow: hidden; }
        }

        /* Desktop: always there, optionally folded to a 4rem icon rail. */
        @media (width >= 48rem) {
            .sb-aside { transition: width 200ms cubic-bezier(.4, 0, .2, 1); }
            .sb-main { transition: margin-left 200ms cubic-bezier(.4, 0, .2, 1); }
            .sb-toggle-icon { transition: rotate 200ms cubic-bezier(.4, 0, .2, 1); }
            /* Labels clip while the width animates, instead of wrapping. */
            .sb-nav { overflow-x: hidden; }

            html.sb-collapsed .sb-aside { width: 4rem; }
            html.sb-collapsed .sb-main { margin-left: 4rem; }
            /* « folds, » unfolds. */
            html.sb-collapsed .sb-toggle-icon { rotate: 180deg; }

            /* Labels leave the screen, not the page: each link keeps its name
               for a screen reader, and the rail's hover tooltip says it too. */
            html.sb-collapsed .sb-label {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip-path: inset(50%);
                white-space: nowrap;
                border: 0;
            }
            html.sb-collapsed .sb-brand,
            html.sb-collapsed .sb-link,
            html.sb-collapsed .sb-foot-link { position: relative; }

            html.sb-collapsed .sb-head { justify-content: center; padding-left: 0; padding-right: 0; }
            html.sb-collapsed .sb-mark { display: grid; }

            /* 10px of side padding plus the links' own 12px puts a 20px icon
               dead centre in the 64px rail — at exactly the x it has in the wide
               sidebar, so the icons hold still while the width animates. */
            html.sb-collapsed .sb-nav,
            html.sb-collapsed .sb-foot { padding-left: .625rem; padding-right: .625rem; }
            /* A classic scrollbar would eat a quarter of the rail. The list
               still scrolls with the wheel and the keyboard. */
            html.sb-collapsed .sb-nav { scrollbar-width: none; }
            html.sb-collapsed .sb-nav::-webkit-scrollbar { display: none; }

            /* A count with no room beside its label sits on the icon's corner. */
            html.sb-collapsed .sb-badge {
                position: absolute;
                top: .25rem;
                left: 1.75rem;
                margin: 0;
                min-width: 1rem;
                height: 1rem;
                padding: 0 .1875rem;
                font-size: 9px;
                box-shadow: 0 0 0 2px #161618;
            }

            html.sb-collapsed .sb-foot-link { display: flex; align-items: center; padding-top: .5rem; padding-bottom: .5rem; }
            html.sb-collapsed .sb-foot-link:hover { background-color: rgb(255 255 255 / .05); }
            html.sb-collapsed .sb-foot-icon { display: block; }
        }

        @media (prefers-reduced-motion: reduce) {
            .sb-aside, .sb-main, .sb-backdrop, .sb-toggle-icon { transition: none !important; }
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Alpine is bundled via Vite (resources/js/app.js) — no CDN. --}}
</head>
{{-- `sidebar` is the phone drawer; `rail` is the folded desktop sidebar. They
     are separate on purpose: opening the drawer on a phone must not fold the
     sidebar on the owner's laptop, and only the rail is worth remembering.

     tip() gives the rail its hover tooltips. In the wide sidebar every label is
     already on screen, and a tooltip repeating it is noise. --}}
<body class="bg-ink-50 text-ink-800"
      x-data="{
          sidebar: false,
          rail: document.documentElement.classList.contains('sb-collapsed'),
          openDrawer() {
              this.sidebar = true;
              this.$nextTick(() => this.$refs.sidebarClose?.focus({ preventScroll: true }));
          },
          closeDrawer() {
              if (! this.sidebar) return;
              this.sidebar = false;
              if (this.$refs.sidebar?.contains(document.activeElement)) this.$refs.menuButton?.focus({ preventScroll: true });
          },
          setRail(on) {
              this.rail = on;
              document.documentElement.classList.toggle('sb-collapsed', on);
          },
          toggleRail() {
              this.setRail(! this.rail);
              try { localStorage.setItem('adminSidebarCollapsed', this.rail ? '1' : '0'); } catch (e) {}
          },
          tip(label) {
              return this.rail ? label : null;
          },
      }"
      :class="{ 'sb-open': sidebar }"
      @keydown.escape.window="closeDrawer()"
      x-on:storage.window="$event.key === 'adminSidebarCollapsed' && setRail($event.newValue === '1')">
<div class="min-h-screen flex">
    {{-- Phone only. Before this, an open drawer covered the very button that
         opened it, and the only way out was to pick a page. It sits under the
         drawer (same z, earlier in the page) and over the sticky header (z-30). --}}
    <div class="sb-backdrop fixed inset-0 z-40" data-sidebar-backdrop @click="closeDrawer()" aria-hidden="true"></div>

    <!-- Sidebar -->
    <aside id="admin-sidebar" x-ref="sidebar" class="sb-aside fixed inset-y-0 left-0 z-40 w-60 bg-ink-900 text-gold-100/80 flex flex-col">
        <div class="sb-head h-16 flex items-center justify-between gap-2 px-5 border-b border-white/10 shrink-0">
            {{-- The rail has no room for the name, so it shows the first letter
                 in its place; the name stays in the link for screen readers. --}}
            <a href="{{ route('admin.dashboard') }}" class="sb-brand min-w-0 font-display text-xl font-bold text-gold-300" :title="tip(@js(store_name()))">
                <span class="sb-label block truncate">{{ store_name() }}</span>
                <span class="sb-mark h-9 w-9 place-items-center rounded-lg bg-white/5 text-lg" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(store_name(), 0, 1)) }}</span>
            </a>

            <button type="button" x-ref="sidebarClose" @click="closeDrawer()"
                    class="sb-needs-js md:hidden grid h-9 w-9 shrink-0 place-items-center rounded-lg text-gold-100/70 hover:text-white hover:bg-white/10 transition"
                    aria-label="Close menu" aria-controls="admin-sidebar">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        {{-- Every entry has its own icon. In the rail the icon is all there is,
             so 2026-09-17 replaced the ones that were shared or read as the
             phone's hamburger: Suppliers (was Products' box), Coupons (was
             Purchase orders' page), Staff & roles (was Customers' people), SMS
             (was Chat history's bubble), Categories and Menu (both three lines),
             Marketing (was a second house beside Dashboard) and Landing pages
             (was a near-twin of Story templates). --}}
        @php $nav = [
            ['dashboard','Dashboard','M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            ['orders.index','Orders','M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
            // Calls to ring back (owner, 2026-09-17): an outgoing call, beside
            // the orders those calls turn into. SMS keeps the handheld phone.
            ['reminders.index','Call reminders','M20.25 3.75v4.5m0-4.5h-4.5m4.5 0l-6 6m3 12c-8.284 0-15-6.716-15-15V4.5A2.25 2.25 0 014.5 2.25h1.372c.516 0 .966.351 1.091.852l1.106 4.423c.11.44-.054.902-.417 1.173l-1.293.97a1.062 1.062 0 00-.38 1.21 12.035 12.035 0 007.143 7.143c.441.162.928-.004 1.21-.38l.97-1.293a1.125 1.125 0 011.173-.417l4.423 1.106c.5.125.852.575.852 1.091V19.5a2.25 2.25 0 01-2.25 2.25h-2.25z'],
            ['customers.index','Customers','M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
            ['products.index','Products','M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['media.index','Media','M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z'],
            ['categories.index','Categories','M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z'],
            ['collections.index','Collections','M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 8.25V6zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25A2.25 2.25 0 0113.5 8.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 018.25 20.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z'],
            ['suppliers.index','Suppliers','M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12'],
            ['purchase-orders.index','Purchase orders','M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            // What the business spends (owner, 2026-09-19), beside the other
            // money going out. Banknotes — no other entry uses them.
            ['expenses.index','Expenses','M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z'],
            ['coupons.index','Coupons','M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z'],
            ['offers.index','Offers','M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z'],
            ['reviews.index','Reviews','M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z'],
            ['conversations.index','Chat history','M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-3.6A8 8 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
            ['notifications.index','Notifications','M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0'],
            ['drips.index','Drip campaigns','M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z'],
            ['segments.index','Customer groups','M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z'],
            ['abandoned.index','Abandoned carts','M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
            ['sms.index','SMS','M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3'],
            ['appearance','Appearance','M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42'],
            ['menu','Menu','M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z'],
            ['pages','Pages','M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
            ['content-templates.index','Story templates','M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6'],
            ['marketing.index','Marketing','M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46'],
            ['landing.index','Landing pages','M3 8.25V18a2.25 2.25 0 002.25 2.25h13.5A2.25 2.25 0 0021 18V8.25m-18 0V6a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 6v2.25m-18 0h18M5.25 6h.008v.008H5.25V6zM7.5 6h.008v.008H7.5V6zm2.25 0h.008v.008H9.75V6z'],
            ['knowledge.index','Knowledge','M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25'],
            ['settings','Settings','M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
            ['system-config.index','System Config','M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5l-3.9 19.5m-2.1-19.5l-3.9 19.5'],
            ['users.index','Staff & roles','M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
            ['profile','My profile','M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z'],
        ]; @endphp
        @php
            // Counts that need someone's attention, keyed by the nav route they
            // sit on. Left uncached deliberately: both columns are indexed
            // (contact_messages.is_read, and orders' [status, created_at]), so
            // these are two cheap COUNTs — and a badge that lags a minute behind
            // the order you just moved to Processing is worse than the query.
            $navBadges = [
                'dashboard' => [
                    'count' => \App\Models\ContactMessage::where('is_read', false)->count(),
                    'title' => 'unread message(s)',
                ],
                'orders.index' => [
                    'count' => \App\Models\Order::where('status', 'processing')->count(),
                    'title' => 'order(s) being processed',
                ],
                // Leads nobody has chased yet. Covered by the (recovered,
                // contacted) index so this stays a cheap count on every render.
                'abandoned.index' => [
                    'count' => \App\Models\AbandonedCart::open()->count(),
                    'title' => 'abandoned cart(s) waiting for follow-up',
                ],
                // Calls whose time has come (owner, 2026-09-17). Covered by the
                // (done_at, due_at) index; dueCount() answers 0 rather than take
                // every admin page down if the table is missing mid-deploy.
                'reminders.index' => [
                    'count' => \App\Models\CallReminder::dueCount(),
                    'title' => 'call reminder(s) due',
                ],
            ];
        @endphp
        <nav class="sb-nav p-3 space-y-1 flex-1 overflow-y-auto min-h-0">
            @foreach($nav as [$route, $label, $icon])
                @continue(! auth()->user()->canAccess(\Illuminate\Support\Str::before($route, '.')))
                @php
                    $badge = $navBadges[$route] ?? null;
                    $badgeShown = $badge && $badge['count'] > 0;
                    $navActive = request()->routeIs('admin.'.\Illuminate\Support\Str::before($route, '.').'*');
                @endphp
                {{-- No aria-label here: the label span below is the link's name,
                     and it stays readable in the rail. --}}
                <a href="{{ route('admin.'.$route) }}"
                   class="sb-link flex items-center gap-3 whitespace-nowrap rounded-lg px-3 py-2.5 text-sm {{ $navActive ? 'bg-white/10 text-white' : 'hover:bg-white/5' }}"
                   @if($navActive) aria-current="page" @endif
                   :title="tip(@js($badgeShown ? $label.' · '.$badge['count'].' '.$badge['title'] : $label))">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                    <span class="sb-label">{{ $label }}</span>
                    @if($badgeShown)
                        <span class="sb-badge ml-auto min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold inline-flex items-center justify-center"
                              title="{{ $badge['count'] }} {{ $badge['title'] }}">{{ $badge['count'] > 9 ? '9+' : $badge['count'] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
        {{-- The wide sidebar keeps its text links; the rail swaps them for icons. --}}
        <div class="sb-foot shrink-0 p-3 border-t border-white/10 whitespace-nowrap overflow-hidden">
            <a href="{{ route('home') }}" target="_blank" class="sb-foot-link block rounded-lg text-xs text-gold-100/50 hover:text-white px-3 py-1" :title="tip('View store')">
                <svg class="sb-foot-icon w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                <span class="sb-label"><span aria-hidden="true">↗</span> View store</span>
            </a>
            <form action="{{ route('admin.logout') }}" method="POST">@csrf<button class="sb-foot-link w-full text-left text-sm px-3 py-2 hover:bg-white/5 rounded-lg" :title="tip('Log out')">
                <svg class="sb-foot-icon w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
                <span class="sb-label">Log out</span>
            </button></form>
        </div>
    </aside>

    <div class="sb-main flex-1 min-w-0 md:ml-60">
        {{-- Exactly one <header> holding exactly one <h1>: admin-ajax.js finds
             the page title as `header h1` when it swaps a page in place.
             z-[35]: above in-page sticky strips and popovers (z-30), which used
             to paint over the bell and its panel, but under the phone drawer
             and its backdrop (z-40), which come earlier in the page. --}}
        <header class="h-16 bg-white border-b border-ink-100 flex items-center justify-between gap-3 px-4 sticky top-0 z-[35]">
            <div class="flex min-w-0 items-center gap-2">
                <button type="button" x-ref="menuButton" @click="openDrawer()"
                        class="sb-needs-js md:hidden p-2 rounded-lg hover:bg-ink-50"
                        aria-label="Open menu" aria-controls="admin-sidebar" aria-expanded="false" :aria-expanded="sidebar">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                </button>
                {{-- Desktop fold/unfold, in the same corner as the phone's menu
                     button, so the control is in one place on every screen. The
                     icon's direction comes from the <html> class, so it is right
                     from the first paint; the label follows once Alpine is up. --}}
                <button type="button" data-sidebar-toggle @click="toggleRail()"
                        class="sb-needs-js hidden md:grid h-9 w-9 shrink-0 place-items-center rounded-lg text-ink-700/60 hover:text-ink-900 hover:bg-ink-50 transition"
                        aria-controls="admin-sidebar"
                        aria-label="Collapse sidebar" :aria-label="rail ? 'Expand sidebar' : 'Collapse sidebar'"
                        title="Collapse sidebar" :title="rail ? 'Expand sidebar' : 'Collapse sidebar'">
                    <svg class="sb-toggle-icon w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5"/></svg>
                </button>
                <h1 class="min-w-0 truncate font-display text-lg font-semibold">@yield('heading', '')</h1>
            </div>

            <div class="flex shrink-0 items-center gap-3">
                @php
                    $alertList = app(\App\Services\AdminAlerts::class)->for(auth()->user());
                    $alertUnread = $alertList->reject(fn ($a) => $a['read'])->count();
                    $alertTone = [
                        'urgent' => 'bg-red-500',
                        'warning' => 'bg-amber-500',
                        'info' => 'bg-sky-500',
                    ];
                @endphp
                {{-- Everything wanting attention, in one place. Alerts are derived
                     from live data, so one disappears by itself once it's dealt
                     with — the read state only silences things you can't fix now.

                     It moved here from the sidebar's head on 2026-09-17, with the
                     fold: a 4rem rail has no room for it, a phone had to open the
                     drawer to reach it, and the new-order toast used to appear
                     inside a drawer that was off the screen. One instance only —
                     a second would poll the feed twice. --}}
                <div class="relative shrink-0"
                     x-data="adminAlerts({ feed: '{{ route('admin.alerts.feed') }}', unread: {{ $alertUnread }}, latestOrderId: {{ (int) \App\Models\Order::max('id') }} })"
                     x-init="start()"
                     @click.outside="bell = false" @keydown.escape.window="bell = false">
                    <button type="button" @click="bell = !bell"
                            class="relative grid h-9 w-9 place-items-center rounded-lg text-ink-700/60 hover:text-ink-900 hover:bg-ink-50 transition"
                            :aria-expanded="bell" aria-label="Notifications">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                        </svg>
                        <span x-show="unread > 0" x-cloak
                              class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold inline-flex items-center justify-center"
                              :class="pulse && 'animate-ping-once'"
                              x-text="unread > 9 ? '9+' : unread"></span>
                    </button>

                    <div x-show="bell" x-cloak x-transition
                         class="sb-pop absolute right-0 top-11 z-50 w-[22rem] max-h-[70vh] overflow-y-auto rounded-xl border border-ink-100 bg-white text-ink-900 shadow-2xl">
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-ink-100 sticky top-0 bg-white">
                            <span class="text-sm font-semibold">Notifications</span>
                            @if($alertUnread > 0)
                                <form method="POST" action="{{ route('admin.alerts.read-all') }}">
                                    @csrf
                                    <button class="text-xs text-gold-700 hover:underline">Mark all read</button>
                                </form>
                            @endif
                        </div>

                        {{-- Live rows (from the poll). The server-rendered list
                             below stays as the no-JS / first-paint fallback. --}}
                        <template x-if="items.length">
                            <div>
                                <template x-for="a in items" :key="a.key">
                                    <form method="POST" action="{{ route('admin.alerts.read') }}" class="block border-b border-ink-50 last:border-0">
                                        @csrf
                                        <input type="hidden" name="key" :value="a.key">
                                        <input type="hidden" name="url" :value="a.url">
                                        <button class="w-full text-left px-4 py-3 hover:bg-ink-50 transition flex gap-3" :class="a.read && 'opacity-45'">
                                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                                  :class="a.read ? 'bg-ink-300' : (a.level === 'urgent' ? 'bg-red-500' : (a.level === 'warning' ? 'bg-amber-500' : 'bg-sky-500'))"></span>
                                            <template x-if="a.image">
                                                <img :src="a.image" alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded object-cover bg-ink-50">
                                            </template>
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium" x-text="a.title"></span>
                                                <span class="block text-xs text-ink-700/60 mt-0.5" x-text="a.body"></span>
                                                <span class="block text-[11px] text-ink-700/40 mt-1" x-text="a.at"></span>
                                            </span>
                                        </button>
                                    </form>
                                </template>
                            </div>
                        </template>

                        <div x-show="!items.length">
                        @forelse($alertList as $a)
                            <form method="POST" action="{{ route('admin.alerts.read') }}" class="block border-b border-ink-50 last:border-0">
                                @csrf
                                <input type="hidden" name="key" value="{{ $a['key'] }}">
                                <input type="hidden" name="url" value="{{ $a['url'] }}">
                                <button class="w-full text-left px-4 py-3 hover:bg-ink-50 transition flex gap-3 {{ $a['read'] ? 'opacity-45' : '' }}">
                                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $a['read'] ? 'bg-ink-300' : ($alertTone[$a['level']] ?? 'bg-ink-400') }}"></span>
                                    @if(! empty($a['image']))
                                        <img src="{{ $a['image'] }}" alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded object-cover bg-ink-50">
                                    @endif
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ $a['title'] }}</span>
                                        <span class="block text-xs text-ink-700/60 mt-0.5">{{ $a['body'] }}</span>
                                        @if($a['at'])
                                            <span class="block text-[11px] text-ink-700/40 mt-1">{{ $a['at']->diffForHumans() }}</span>
                                        @endif
                                    </span>
                                </button>
                            </form>
                        @empty
                            <p class="px-4 py-8 text-center text-sm text-ink-700/50">Nothing needs your attention.</p>
                        @endforelse
                        </div>
                    </div>

                    {{-- New-order toast: appears the moment the poll sees an id
                         higher than the one this page was rendered with. --}}
                    <div x-show="toast" x-cloak x-transition
                         class="sb-pop absolute right-0 top-11 z-[60] w-72 rounded-xl bg-ink-900 text-white shadow-2xl px-4 py-3 flex items-start gap-3">
                        <span class="text-xl leading-none">🛎️</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold" x-text="toast"></p>
                            <a href="{{ route('admin.orders.index') }}" class="text-xs text-gold-300 hover:underline">Open orders →</a>
                        </div>
                        <button type="button" @click="toast = ''" class="text-white/50 hover:text-white leading-none">&times;</button>
                    </div>
                </div>

                {{-- On a phone the heading needs the room more than the name does. --}}
                <div class="hidden sm:block text-sm text-ink-700/60">{{ auth()->user()->name ?? '' }}</div>
            </div>
        </header>

        {{-- Wrapped so admin-ajax.js can swap it after a background submit
             without reloading the page. --}}
        <div id="admin-flash">
        @if(session('success'))<div class="m-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">{{ session('success') }}</div>@endif
        @if(session('warning'))<div class="m-4 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">{{ session('warning') }}</div>@endif
        @if(session('error'))<div class="m-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ session('error') }}</div>@endif
        @if(session('import_errors') && count(session('import_errors')))
            <div class="m-4 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">
                <p class="font-medium mb-1">Some rows were skipped:</p>
                <ul class="list-disc pl-5 space-y-0.5">
                    @foreach(session('import_errors') as $importError)<li>{{ $importError }}</li>@endforeach
                </ul>
            </div>
        @endif
        </div>

        <main class="p-4 sm:p-6">@yield('content')</main>
    </div>
</div>
@include('admin.partials.media-picker')
@stack('scripts')
</body>
</html>
