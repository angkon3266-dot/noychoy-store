import { useEffect, useRef, useState } from 'react';

// Native share sheet where available (phones), copy-link menu elsewhere —
// a React port of the Alpine shareBox component.
export default function ShareButton({ url = null, title = null, label = 'Share', compact = false, placement = 'up-right' }) {
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const ref = useRef(null);

    const shareUrl = url || (typeof window !== 'undefined' ? window.location.href : '');
    const shareTitle = title || (typeof document !== 'undefined' ? document.title : '');

    useEffect(() => {
        const close = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const trigger = async () => {
        if (navigator.share) {
            try {
                await navigator.share({ title: shareTitle, text: shareTitle, url: shareUrl });
                return;
            } catch (e) {
                if (e && e.name === 'AbortError') return;
            }
        }
        setOpen(!open);
    };

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(shareUrl);
        } catch (e) { /* clipboard unavailable */ }
        setCopied(true);
        setTimeout(() => { setCopied(false); setOpen(false); }, 1400);
    };

    const menuPos = placement === 'up-right' ? 'bottom-full left-0 mb-2' : 'top-full left-0 mt-2';
    const encoded = encodeURIComponent(shareUrl);

    return (
        <div className="relative" ref={ref}>
            <button type="button" onClick={trigger} className={compact ? 'p-2 text-ink-700/70 hover:text-gold-700' : 'inline-flex items-center gap-1.5 text-sm text-ink-700/70 hover:text-gold-700'} title={label} aria-label={label}>
                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" /></svg>
                {!compact && <span>{label}</span>}
            </button>
            {open && (
                <div className={`absolute ${menuPos} z-50 w-44 rounded-xl border border-ink-100 bg-white shadow-xl p-1.5 text-sm`}>
                    <a href={`https://www.facebook.com/sharer/sharer.php?u=${encoded}`} target="_blank" rel="noopener" className="block rounded-lg px-3 py-2 hover:bg-gold-50">Facebook</a>
                    <a href={`https://wa.me/?text=${encodeURIComponent(shareTitle + ' ' + shareUrl)}`} target="_blank" rel="noopener" className="block rounded-lg px-3 py-2 hover:bg-gold-50">WhatsApp</a>
                    <button type="button" onClick={copy} className="block w-full text-left rounded-lg px-3 py-2 hover:bg-gold-50">
                        {copied ? '✓ Copied!' : 'Copy link'}
                    </button>
                </div>
            )}
        </div>
    );
}
