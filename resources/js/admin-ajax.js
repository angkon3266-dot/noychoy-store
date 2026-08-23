/**
 * Admin forms without the full-page reload.
 *
 * Every admin screen is Blade, and every action on one — changing an order's
 * status, saving a setting, sending an SMS — was a full navigation: white
 * flash, scroll position lost, and on the order page a redirect straight back
 * into a screen that re-renders everything. Doing dozens of them in a row is
 * the owner's whole working day.
 *
 * This is deliberately NOT a rewrite. It intercepts the submit, posts in the
 * background, then fetches the resulting page and swaps in the parts that
 * changed. Anything it cannot handle confidently falls through to a normal
 * browser submit, so the worst case is exactly the behaviour we had before.
 *
 * Opt out with data-no-ajax on the form.
 */

const MAIN = 'main';
const FLASH_ZONE = 'admin-flash';

function toast(message, kind = 'success') {
    let el = document.getElementById('admin-toast');

    if (!el) {
        el = document.createElement('div');
        el.id = 'admin-toast';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[200] rounded-full px-5 py-2.5 text-sm shadow-lg transition-all duration-300 opacity-0 translate-y-2 pointer-events-none';
        document.body.appendChild(el);
    }

    el.textContent = message;
    el.className = el.className.replace(/bg-\S+|text-\S+/g, '');
    el.classList.add(kind === 'error' ? 'bg-red-600' : 'bg-ink-900', 'text-white');
    requestAnimationFrame(() => {
        el.classList.remove('opacity-0', 'translate-y-2');
    });

    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.classList.add('opacity-0', 'translate-y-2'), 3200);
}

/** Should this form be handled here at all? */
function eligible(form) {
    if (form.hasAttribute('data-no-ajax')) return false;
    // A GET form is a navigation, not a mutation.
    if ((form.method || 'get').toLowerCase() !== 'post') return false;
    // Anything aimed at another window, or expected to produce a download, has
    // to be a real submit — fetch cannot hand the browser a file to save.
    if (form.target && form.target !== '_self') return false;
    if (form.hasAttribute('download')) return false;

    return true;
}

/** Pull the fresh page apart and put the changed bits in place. */
function swap(html, url) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fresh = doc.querySelector(MAIN);
    const current = document.querySelector(MAIN);

    // If the response is not an admin page we recognise (a login redirect, an
    // error page), hand over to the browser rather than guessing.
    if (!fresh || !current) {
        window.location.href = url;

        return false;
    }

    current.innerHTML = fresh.innerHTML;

    // Flash messages live above <main>, so they are swapped separately.
    const freshFlash = doc.getElementById(FLASH_ZONE);
    const currentFlash = document.getElementById(FLASH_ZONE);
    if (freshFlash && currentFlash) {
        currentFlash.innerHTML = freshFlash.innerHTML;
    }

    const heading = doc.querySelector('header h1');
    const currentHeading = document.querySelector('header h1');
    if (heading && currentHeading) {
        currentHeading.textContent = heading.textContent;
    }

    if (doc.title) {
        document.title = doc.title;
    }

    // Keep the address bar honest — a create that redirected to the new record
    // must be linkable and reloadable.
    if (url && url !== window.location.href) {
        window.history.replaceState({}, '', url);
    }

    return true;
}

function busy(form, on) {
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach((b) => {
        b.disabled = on;
        b.classList.toggle('opacity-60', on);
    });
}

async function handle(e) {
    const form = e.target;

    if (!(form instanceof HTMLFormElement) || !eligible(form)) {
        return;
    }

    e.preventDefault();
    busy(form, true);

    try {
        const res = await fetch(form.action || window.location.href, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            redirect: 'follow',
            credentials: 'same-origin',
        });

        // A session that expired mid-edit, or anything we should not try to
        // interpret: let the browser do it properly.
        if (res.status === 401 || res.status === 419 || res.status >= 500) {
            // form.submit() does not fire a submit event, so this cannot
            // loop back into the handler.
            form.submit();

            return;
        }

        const html = await res.text();

        if (swap(html, res.url)) {
            const flash = document.getElementById(FLASH_ZONE);
            const message = flash?.textContent.trim();

            if (message) {
                toast(message, flash.querySelector('.bg-red-50') ? 'error' : 'success');
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    } catch (err) {
        // Offline, blocked, anything unexpected — the normal submit is always
        // the safe answer.
        form.submit();
    } finally {
        busy(form, false);
    }
}

// Delegated, so it covers forms that arrive with a swapped page too.
document.addEventListener('submit', (e) => {
    const form = e.target;

    if (form instanceof HTMLFormElement && eligible(form)) {
        handle(e);
    }
});

/**
 * Submit a form the way a user would.
 *
 * Inline handlers used `form.submit()`, which does NOT fire a submit event —
 * so nothing above could intercept it and every one of those controls did a
 * full page navigation. requestSubmit() does fire it; the fallback is for
 * older Safari, where the old behaviour is still correct, just not enhanced.
 */
window.submitForm = function (form) {
    if (!form) return;

    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.submit();
    }
};
