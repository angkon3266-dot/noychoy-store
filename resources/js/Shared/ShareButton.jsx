import { useEffect, useRef, useState } from 'react';

// Native share sheet where available (phones), copy-link menu elsewhere —
// a React port of the Alpine shareBox component.
//
// `hint` ({ href, label, note }) adds "Drop a hint" to the menu (the product
// page, Nov 2026). The menu then always opens first, even on phones — the
// native sheet cannot carry it — and the sheet moves to "More…".
// `variant="round"` is the icon-only button the product page sits beside the heart.
export default function ShareButton({ url = null, title = null, label = 'Share', compact = false, placement = 'up-right', hint = null, variant = null }) {
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const ref = useRef(null);

    const shareUrl = url || (typeof window !== 'undefined' ? window.location.href : '');
    const shareTitle = title || (typeof document !== 'undefined' ? document.title : '');
    const canNative = typeof navigator !== 'undefined' && !!navigator.share;

    useEffect(() => {
        const close = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('click', close);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    const native = async () => {
        try {
            await navigator.share({ title: shareTitle, text: shareTitle, url: shareUrl });
            return true;
        } catch (e) {
            return !!(e && e.name === 'AbortError');
        }
    };

    const trigger = async () => {
        if (!hint && canNative && await native()) return;
        setOpen(!open);
    };

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(shareUrl);
        } catch (e) { /* clipboard unavailable */ }
        setCopied(true);
        setTimeout(() => { setCopied(false); setOpen(false); }, 1400);
    };

    const menuPos = {
        'up-right': 'bottom-full left-0 mb-2',
        'down-left': 'top-full right-0 mt-2',
    }[placement] || 'top-full left-0 mt-2';
    const encoded = encodeURIComponent(shareUrl);
    const buttonClass = variant === 'round'
        ? 'grid h-10 w-10 place-items-center rounded-full text-ink-700/80 hover:bg-ink-900/[0.05] hover:text-ink-900 transition'
        : compact ? 'p-2 text-ink-700/70 hover:text-gold-700' : 'inline-flex items-center gap-1.5 text-sm text-ink-700/70 hover:text-gold-700';
    const item = 'block w-full text-left rounded-lg px-3 py-2 hover:bg-gold-50';

    return (
        <div className="relative" ref={ref}>
            <button type="button" onClick={trigger} className={buttonClass} title={label} aria-label={label} aria-expanded={hint ? open : undefined} aria-haspopup={hint ? 'menu' : undefined}>
                <svg className={variant === 'round' ? 'w-[21px] h-[21px]' : 'w-5 h-5'} fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>
                {!compact && variant !== 'round' && <span>{label}</span>}
            </button>
            {open && (
                <div className={`absolute ${menuPos} z-50 ${hint ? 'w-60' : 'w-44'} rounded-xl border border-ink-100 bg-white shadow-xl p-1.5 text-sm`}>
                    {hint && (
                        <>
                            <a href={hint.href} target="_blank" rel="noopener" onClick={() => setOpen(false)} className={item}>
                                <span className="block font-medium text-ink-900">{hint.label}</span>
                                {hint.note && <span className="block text-xs text-ink-700/60 mt-0.5">{hint.note}</span>}
                            </a>
                            <div className="my-1 h-px bg-ink-900/[0.06]" aria-hidden="true" />
                        </>
                    )}
                    <a href={`https://wa.me/?text=${encodeURIComponent(shareTitle + ' ' + shareUrl)}`} target="_blank" rel="noopener" className={item}>WhatsApp</a>
                    <a href={`https://www.facebook.com/sharer/sharer.php?u=${encoded}`} target="_blank" rel="noopener" className={item}>Facebook</a>
                    <button type="button" onClick={copy} className={item}>
                        {copied ? '✓ Copied!' : 'Copy link'}
                    </button>
                    {hint && canNative && (
                        <button type="button" onClick={() => { setOpen(false); native(); }} className={item}>More…</button>
                    )}
                </div>
            )}
        </div>
    );
}
