// Keep Tab inside an open overlay.
//
// The selector and the wrap logic are copied byte-for-byte from the Alpine
// drawer this storefront replaced (resources/js/app.js), so the React drawers
// trap exactly what the Blade one trapped — no behaviour drift between the two
// while both still exist.
const FOCUSABLE = 'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])';

export function trapTab(e, container) {
    if (e.key !== 'Tab' || !container) return;

    const f = container.querySelectorAll(FOCUSABLE);
    if (!f.length) return;

    const first = f[0];
    const last = f[f.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}
