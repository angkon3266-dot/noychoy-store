{{--
    The chat assistant panel. Included at the end of BOTH root views (the
    Inertia shell and layouts/shop) as a self-mounting island outside #app,
    so it survives Inertia navigations and works on Blade landing pages alike.
    The launcher button lives in the floating stacks; this exposes
    window.NoyChat.{open,close,toggle} for it. Transcript stays in
    sessionStorage — nothing is persisted server-side.
--}}
@php
    $assistant = app(\App\Services\Ai\AssistantService::class);
    $bn = \App\Support\Locale::isBangla();
    $chatConfig = $assistant->enabled() ? [
        'endpoint' => route('assistant.chat'),
        'csrf' => csrf_token(),
        'store' => store_name(),
        'greeting' => $assistant->greeting(),
        'offline' => $assistant->offlineText(),
        // The assistant makes the first move: a small "need anything?" beside
        // the launcher, once per session, never over the checkout form.
        'teaser' => $bn ? 'কিছু লাগবে? 👋 আমাকে জিজ্ঞেস করুন' : 'Need any help? 👋 Ask me anything',
        'chips' => $bn
            ? ['ডেলিভারি চার্জ কত?', '১,০০০ টাকার নিচে গিফট', 'আমার অর্ডার কোথায়?', 'রিং সাইজ কীভাবে মাপব?']
            : ['Delivery charge?', 'Gift under ৳1,000', 'Track my order', 'Ring size help'],
    ] : null;
@endphp
@if($chatConfig)
<div id="noy-chat-teaser" class="noy-chat-teaser" role="status" hidden>
    <button type="button" class="noy-chat-teaser__open" data-noy-tease-open></button>
    <button type="button" class="noy-chat-teaser__x" data-noy-tease-close aria-label="Dismiss">&times;</button>
</div>
<div id="noy-chat" class="noy-chat" data-config='@json($chatConfig)' hidden>
<style>
    .noy-chat{position:fixed;right:1.25rem;bottom:calc(5.75rem + env(safe-area-inset-bottom));z-index:70;width:min(380px,calc(100vw - 2.5rem));max-height:min(72vh,640px);display:flex;flex-direction:column;background:#fff;color:var(--color-ink-900,#161618);border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.22),0 2px 8px rgba(0,0,0,.08);overflow:hidden;font-family:inherit,'Noto Sans Bengali','Nirmala UI','Bangla Sangam MN','Vrinda',sans-serif;font-size:14px;line-height:1.45}
    .noy-chat[hidden]{display:none}
    @media (max-width:640px){.noy-chat{right:.75rem;left:.75rem;width:auto;bottom:calc(5.25rem + env(safe-area-inset-bottom));max-height:calc(100dvh - 7.5rem - env(safe-area-inset-bottom))}}
    .noy-chat__head{display:flex;align-items:center;gap:.6rem;padding:.8rem 1rem;background:var(--color-gold-700,#8a6a3f);color:#fff}
    .noy-chat__head strong{font-size:14px;display:block}
    .noy-chat__head small{display:block;font-size:11px;opacity:.85}
    .noy-chat__dot{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);display:grid;place-items:center;flex:none}
    .noy-chat__close{margin-left:auto;background:transparent;border:0;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:.25rem .4rem;border-radius:8px}
    .noy-chat__close:hover{background:rgba(255,255,255,.15)}
    .noy-chat__log{flex:1;overflow-y:auto;padding:.9rem 1rem .5rem;display:flex;flex-direction:column;gap:.55rem;scroll-behavior:smooth;background:var(--color-gold-50,#fbf7f1)}
    /* flex:none on every log child: the log is a column flexbox that scrolls, and a
       child that may itself scroll (the product cards) would otherwise be shrunk
       to a sliver once the conversation overflows. */
    .noy-chat__msg{flex:none;max-width:88%;padding:.55rem .8rem;border-radius:14px;white-space:pre-wrap;word-break:break-word}
    .noy-chat__msg--user{align-self:flex-end;background:var(--color-gold-200,#e9d7bd);border-bottom-right-radius:4px}
    .noy-chat__msg--bot{align-self:flex-start;background:#fff;border:1px solid var(--color-gold-100,#f1e7d8);border-bottom-left-radius:4px}
    .noy-chat__msg--bot a{color:var(--color-gold-800,#6f5330);text-decoration:underline}
    .noy-chat__msg--err{background:#fff3f0;border-color:#f3c9bf}
    .noy-chat__typing span{display:inline-block;width:6px;height:6px;margin:0 2px;border-radius:50%;background:var(--color-gold-600,#b08768);animation:noy-b 1s infinite}
    .noy-chat__typing span:nth-child(2){animation-delay:.15s}.noy-chat__typing span:nth-child(3){animation-delay:.3s}
    @keyframes noy-b{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}
    .noy-chat__cards{flex:none;display:flex;gap:.5rem;overflow-x:auto;padding:.1rem 0 .3rem;align-self:stretch;scrollbar-width:thin}
    .noy-chat__card{flex:0 0 150px;background:#fff;border:1px solid var(--color-gold-100,#f1e7d8);border-radius:12px;padding:.5rem;text-decoration:none;color:inherit;display:block}
    .noy-chat__card img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:8px;background:var(--color-gold-100,#f1e7d8)}
    .noy-chat__card b{display:block;font-size:12px;font-weight:600;margin-top:.4rem;line-height:1.3;max-height:2.6em;overflow:hidden}
    .noy-chat__card span{display:block;font-size:12px;color:var(--color-gold-800,#6f5330);margin-top:.15rem}
    .noy-chat__card i{display:block;font-size:10px;font-style:normal;color:#8a7f72}
    .noy-chat__chips{display:flex;flex-wrap:wrap;gap:.4rem;padding:.4rem 1rem .2rem;background:var(--color-gold-50,#fbf7f1)}
    .noy-chat__chip{border:1px solid var(--color-gold-300,#d9bf98);background:#fff;border-radius:999px;padding:.3rem .7rem;font-size:12px;cursor:pointer;color:inherit;font-family:inherit}
    .noy-chat__chip:hover{background:var(--color-gold-100,#f1e7d8)}
    .noy-chat__form{display:flex;gap:.5rem;align-items:flex-end;padding:.6rem .75rem;border-top:1px solid var(--color-gold-100,#f1e7d8);background:#fff}
    .noy-chat__input{flex:1;resize:none;border:1px solid var(--color-gold-200,#e9d7bd);border-radius:12px;padding:.55rem .75rem;font:inherit;line-height:1.4;max-height:120px;outline:none;font-family:inherit,'Noto Sans Bengali','Nirmala UI','Bangla Sangam MN','Vrinda',sans-serif}
    .noy-chat__input:focus{border-color:var(--color-gold-600,#b08768);box-shadow:0 0 0 3px rgba(176,135,104,.18)}
    .noy-chat__send{flex:none;width:40px;height:40px;border-radius:50%;border:0;background:var(--color-ink-900,#161618);color:#fff;cursor:pointer;display:grid;place-items:center}
    .noy-chat__send:disabled{opacity:.45;cursor:default}
    .noy-chat__foot{font-size:10px;color:#8a7f72;text-align:center;padding:0 .75rem .5rem;background:#fff}
    /* The opening line beside the launcher; right/top are set from the launcher's box. */
    .noy-chat-teaser{position:fixed;z-index:69;transform:translateY(-50%);display:flex;align-items:center;gap:.1rem;max-width:min(270px,calc(100vw - 6.75rem));background:#fff;color:var(--color-ink-900,#161618);border:1px solid var(--color-gold-200,#e9d7bd);border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.18);padding:.15rem .25rem .15rem .15rem;font-family:inherit,'Noto Sans Bengali','Nirmala UI','Bangla Sangam MN','Vrinda',sans-serif;animation:noy-pop .3s ease-out}
    .noy-chat-teaser[hidden]{display:none}
    .noy-chat-teaser:after{content:"";position:absolute;right:-6px;top:50%;width:10px;height:10px;margin-top:-5px;background:#fff;border-top:1px solid var(--color-gold-200,#e9d7bd);border-right:1px solid var(--color-gold-200,#e9d7bd);transform:rotate(45deg)}
    .noy-chat-teaser__open{border:0;background:transparent;font:inherit;font-size:13px;line-height:1.35;color:inherit;text-align:left;padding:.5rem .3rem .5rem .65rem;cursor:pointer;font-family:inherit}
    .noy-chat-teaser__x{flex:none;border:0;background:transparent;color:#8a7f72;font-size:18px;line-height:1;padding:.2rem .4rem;cursor:pointer;border-radius:8px}
    .noy-chat-teaser__x:hover{background:var(--color-gold-100,#f1e7d8)}
    @keyframes noy-pop{from{opacity:0;transform:translateY(-50%) translateX(8px)}to{opacity:1;transform:translateY(-50%)}}
</style>
<div class="noy-chat__head">
    <div class="noy-chat__dot" aria-hidden="true"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.652.213-2.76 1.66-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg></div>
    <div><strong>{{ $assistant->name() }}</strong><small>English · বাংলা · Banglish &nbsp;·&nbsp; answers in seconds</small></div>
    <button type="button" class="noy-chat__close" data-noy-close aria-label="Close chat">&times;</button>
</div>
<div class="noy-chat__log" data-noy-log role="log" aria-live="polite" aria-label="Conversation"></div>
<div class="noy-chat__chips" data-noy-chips></div>
<form class="noy-chat__form" data-noy-form>
    <label class="sr-only" for="noy-chat-input" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Your message</label>
    <textarea id="noy-chat-input" class="noy-chat__input" rows="1" maxlength="{{ \App\Services\Ai\AssistantService::MAX_CHARS }}" placeholder="Ask about a piece, delivery, or your order…" data-noy-input></textarea>
    <button type="submit" class="noy-chat__send" data-noy-send aria-label="Send"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.27 3.13a.5.5 0 01.67-.6L21 12 3.94 21.47a.5.5 0 01-.67-.6L6 12zm0 0h6"/></svg></button>
</form>
{{-- Says what it is and, when it can take orders, what it will ask for. A
     customer typing her home address deserves to know where it is going. --}}
<p class="noy-chat__foot">
    @if(app(\App\Services\Ai\ChatOrder::class)->enabled())
        {{ $bn
            ? 'AI অ্যাসিস্ট্যান্ট — দাম ও স্টক লাইভ স্টোর থেকে। অর্ডার নিতে নাম, নম্বর ও ঠিকানা জিজ্ঞেস করবে; আপনি নিশ্চিত না করা পর্যন্ত কোনো অর্ডার হবে না।'
            : 'AI assistant — prices and stock come from the live store. To place an order it will ask for your name, number and address; nothing is ordered until you confirm.' }}
    @else
        {{ $bn
            ? 'AI অ্যাসিস্ট্যান্ট — দাম ও অর্ডারের তথ্য লাইভ স্টোর থেকে। জরুরি কিছু হলে কল করুন।'
            : 'AI assistant — prices and order status come from the live store. For anything urgent, call us.' }}
    @endif
</p>
<script>
(function () {
    var root = document.getElementById('noy-chat');
    if (!root || root.dataset.noyReady) return;
    root.dataset.noyReady = '1';

    var cfg = JSON.parse(root.dataset.config);
    var log = root.querySelector('[data-noy-log]');
    var chips = root.querySelector('[data-noy-chips]');
    var form = root.querySelector('[data-noy-form]');
    var input = root.querySelector('[data-noy-input]');
    var send = root.querySelector('[data-noy-send]');
    var KEY = 'noychat.v1';
    var MAX_TURNS = {{ \App\Services\Ai\AssistantService::MAX_TURNS }};
    var msgs = [];
    var busy = false;

    try { msgs = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { msgs = []; }
    if (!Array.isArray(msgs)) msgs = [];

    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(msgs.slice(-40))); } catch (e) {} }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return (m && m.getAttribute('content')) || cfg.csrf;
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function isBangla(s) { return /[ঀ-৿]/.test(s); }

    // Tiny renderer: **bold**, "- " bullets, and same-origin links only.
    function rich(text) {
        var html = esc(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/^(?:- |• )(.*)$/gm, '• $1');
        var origin = location.origin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        html = html.replace(new RegExp('(' + origin + '[^\\s<]*)', 'g'), function (u) {
            var clean = u.replace(/[.,;:!?)]+$/, '');
            return '<a href="' + clean + '">' + clean.replace(location.origin, '') + '</a>' + u.slice(clean.length);
        });
        // The store's WhatsApp is the one off-site link allowed: it is how a
        // customer reaches a person, so it must be tappable.
        html = html.replace(/https:\/\/wa\.me\/(\d{6,15})/g, '<a href="https://wa.me/$1" target="_blank" rel="noopener">wa.me/$1</a>');
        return html;
    }

    function bubble(role, text, extraClass) {
        var el = document.createElement('div');
        el.className = 'noy-chat__msg noy-chat__msg--' + (role === 'user' ? 'user' : 'bot') + (extraClass ? ' ' + extraClass : '');
        if (isBangla(text)) el.setAttribute('lang', 'bn');
        el.innerHTML = role === 'user' ? esc(text) : rich(text);
        log.appendChild(el);
        return el;
    }

    function cards(products) {
        if (!products || !products.length) return;
        var row = document.createElement('div');
        row.className = 'noy-chat__cards';
        products.forEach(function (p) {
            var a = document.createElement('a');
            a.className = 'noy-chat__card';
            a.href = p.url;
            a.innerHTML = (p.thumb ? '<img src="' + esc(p.thumb) + '" alt="" loading="lazy">' : '')
                + '<b>' + esc(p.name) + '</b>'
                + '<span>' + esc(p.price_text) + (p.member && p.member.price_text ? ' <i>members ' + esc(p.member.price_text) + '</i>' : '') + '</span>'
                + (p.available === false ? '<i>Sold out</i>' : '');
            row.appendChild(a);
        });
        log.appendChild(row);
    }

    function scroll() { log.scrollTop = log.scrollHeight; }

    function renderAll() {
        log.innerHTML = '';
        if (!msgs.length) bubble('assistant', cfg.greeting);
        msgs.forEach(function (m) {
            bubble(m.role, m.content);
            if (m.products) cards(m.products);
        });
        chips.hidden = msgs.length > 0;
        scroll();
    }

    cfg.chips.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'noy-chat__chip';
        b.textContent = c;
        if (isBangla(c)) b.setAttribute('lang', 'bn');
        b.addEventListener('click', function () { ask(c); });
        chips.appendChild(b);
    });

    function typing() {
        var el = document.createElement('div');
        el.className = 'noy-chat__msg noy-chat__msg--bot noy-chat__typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        el.setAttribute('aria-label', 'Typing');
        log.appendChild(el);
        scroll();
        return el;
    }

    async function ask(text) {
        text = String(text || '').trim();
        if (!text || busy) return;
        busy = true;
        send.disabled = true;
        input.value = '';
        input.style.height = '';
        msgs.push({ role: 'user', content: text });
        save();
        chips.hidden = true;
        bubble('user', text);
        var t = typing();

        try {
            var payload = msgs.slice(-MAX_TURNS).map(function (m) { return { role: m.role, content: m.content }; });
            var res = await fetch(cfg.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ messages: payload, page: location.pathname }),
            });
            var data = null;
            try { data = await res.json(); } catch (e) {}
            t.remove();
            if (res.status === 429) {
                bubble('assistant', 'You are sending messages a little too fast — give it a minute and try again.', 'noy-chat__msg--err');
            } else if (res.status === 422) {
                // Only the customer's own turn can be rejected now; say why
                // rather than pretending the assistant is down.
                bubble('assistant', 'That message is a little long — please keep it under 1,200 characters.', 'noy-chat__msg--err');
            } else if (!data || typeof data.reply !== 'string') {
                bubble('assistant', cfg.offline, 'noy-chat__msg--err');
            } else if (!data.ok) {
                // Shown, but not remembered: a "can't answer right now" is not
                // part of the conversation and must not be replayed to the model.
                bubble('assistant', data.reply, 'noy-chat__msg--err');
            } else {
                msgs.push({ role: 'assistant', content: data.reply, products: data.products && data.products.length ? data.products : null });
                save();
                bubble('assistant', data.reply);
                cards(data.products);
            }
        } catch (e) {
            t.remove();
            bubble('assistant', cfg.offline, 'noy-chat__msg--err');
        }
        busy = false;
        send.disabled = false;
        scroll();
        input.focus();
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    input.addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.keyCode === 13) && !e.shiftKey) { e.preventDefault(); ask(input.value); }
    });
    input.addEventListener('input', function () {
        input.style.height = '';
        input.style.height = Math.min(120, input.scrollHeight) + 'px';
    });
    root.querySelector('[data-noy-close]').addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !root.hidden) close(); });

    function open() {
        hideTeaser();
        root.hidden = false;
        renderAll();
        setTimeout(function () { input.focus(); }, 50);
        document.querySelectorAll('[data-noy-chat-launcher]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    }
    function close() {
        root.hidden = true;
        document.querySelectorAll('[data-noy-chat-launcher]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    }
    function toggle() { root.hidden ? open() : close(); }

    // The assistant speaks first. A small "need anything?" appears beside the
    // launcher a few seconds in, once per session (sessionStorage), never on
    // the checkout form, never once a conversation exists. Placed from the
    // launcher's live box, so it sits right on the React and Blade stacks alike.
    var teaser = document.getElementById('noy-chat-teaser');
    var TEASED = 'noychat.teased';
    var teaseTimer = null;

    function placeTeaser() {
        var b = document.querySelector('[data-noy-chat-launcher]');
        if (!b || !teaser) return false;
        var r = b.getBoundingClientRect();
        if (!r.width || !r.height) return false;
        teaser.style.right = Math.round(window.innerWidth - r.left + 10) + 'px';
        teaser.style.top = Math.round(r.top + r.height / 2) + 'px';
        return true;
    }
    function hideTeaser() {
        if (!teaser || teaser.hidden) return;
        teaser.hidden = true;
        clearTimeout(teaseTimer);
    }
    function showTeaser() {
        if (!teaser || !root.hidden || msgs.length || /^\/checkout/.test(location.pathname)) return;
        try { if (sessionStorage.getItem(TEASED)) return; } catch (e) {}
        if (!placeTeaser()) return;
        try { sessionStorage.setItem(TEASED, '1'); } catch (e) {}
        teaser.hidden = false;
        teaseTimer = setTimeout(hideTeaser, 15000);
    }
    if (teaser) {
        var teaseOpen = teaser.querySelector('[data-noy-tease-open]');
        teaseOpen.textContent = cfg.teaser;
        if (isBangla(cfg.teaser)) teaseOpen.setAttribute('lang', 'bn');
        teaseOpen.addEventListener('click', function () { hideTeaser(); open(); });
        teaser.querySelector('[data-noy-tease-close]').addEventListener('click', hideTeaser);
        window.addEventListener('resize', function () { if (!teaser.hidden && !placeTeaser()) hideTeaser(); });
        document.addEventListener('inertia:navigate', hideTeaser);
        setTimeout(showTeaser, 6000);
    }

    window.NoyChat = { open: open, close: close, toggle: toggle, isOpen: function () { return !root.hidden; } };
})();
</script>
</div>
@endif
