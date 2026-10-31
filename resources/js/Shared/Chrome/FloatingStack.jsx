import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon, { Messenger, Phone, WhatsApp } from '../Icons';
import ShareButton from '../ShareButton';
import SmartLink from '../SmartLink';
import { SpinFab, useSpin } from '../SpinWheel';

// Floating stacks: share + back-to-top (left), spin / assistant / member
// offers / call / messenger / WhatsApp (right).
//
// On phones the right stack is one launcher that opens into the buttons.
// The 31 Oct 2026 audit (375 px wide) had the WIN wheel and WhatsApp sitting
// on product cards' "Buy now", on the "Verified buyer" line of a review and
// on the product page's own WhatsApp button, with the back-to-top arrow over
// the card below it on the left. One closed circle covers far less than
// three, and a shopper who wants to chat still gets there in one tap. From
// md up there is room, and the stack stays open as it was.
export default function FloatingStack() {
    const { props, component, url } = usePage();
    const chrome = props.chrome || {};
    const floats = chrome.floats || {};
    const offers = chrome.offers;
    const urls = chrome.urls || {};
    const [showTop, setShowTop] = useState(false);
    const [offersOpen, setOffersOpen] = useState(false);
    const [open, setOpen] = useState(false);
    const offersRef = useRef(null);
    const stackRef = useRef(null);

    // Back to top only once she is well past the fold — a screen and a half,
    // not 600 px, which on a phone is before the first product row has gone by.
    useEffect(() => {
        const onScroll = () => setShowTop(window.scrollY > Math.max(900, window.innerHeight * 1.5));
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        const close = (e) => {
            if (offersRef.current && !offersRef.current.contains(e.target)) setOffersOpen(false);
            if (stackRef.current && !stackRef.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    // A new page starts closed.
    useEffect(() => { setOpen(false); setOffersOpen(false); }, [url]);

    const ai = chrome.ai;
    const spin = useSpin();
    const spinFab = spin && !spin.result && !spin.blocked;
    const rightCount = [spinFab, ai, offers, floats.call, floats.messenger, floats.whatsapp].filter(Boolean).length;
    const anyRight = rightCount > 0;
    // One button needs no launcher; two or more fold behind one on phones.
    const folded = rightCount > 1;

    // The money page gets no floating chrome over its form — except the
    // assistant, which is exactly where a "delivery charge koto?" happens.
    // Product pages lift the stacks above the sticky buy bar so nothing
    // covers "Buy now".
    if (component === 'Checkout') {
        return ai ? (
            <div className="fixed right-5 z-50 bottom-[calc(4.75rem_+_env(safe-area-inset-bottom))] md:bottom-5">
                <button type="button" onClick={() => window.NoyChat?.toggle()} className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-ink-900 text-white shadow-lg hover:scale-105 transition p-3.5" title={ai.label} aria-label={ai.label} data-noy-chat-launcher>
                    <Icon name="chat" className="w-full h-full" strokeWidth={1.8} />
                </button>
            </div>
        ) : null;
    }
    const onProduct = component === 'Product';
    const leftPos = onProduct ? 'bottom-[calc(9.5rem_+_env(safe-area-inset-bottom))] md:bottom-5' : 'bottom-20 md:bottom-5';
    const rightPos = onProduct ? 'bottom-[calc(9.5rem_+_env(safe-area-inset-bottom))] md:bottom-5' : 'bottom-[calc(4.75rem_+_env(safe-area-inset-bottom))] md:bottom-5';

    return (
        <>
            {/* Left: share + back-to-top. Out of the way while the right
                launcher is open on a phone — one thing at a time. */}
            <div className={`fixed left-5 z-40 flex-col items-center gap-3 ${leftPos} ${open ? 'hidden md:flex' : 'flex'}`}>
                {floats.share && (
                    <div className="w-11 h-11 rounded-full bg-white shadow-lg border border-ink-100 flex items-center justify-center hover:border-gold-300 transition">
                        <ShareButton compact label="Share this page" placement="up-right" />
                    </div>
                )}
                <button
                    type="button"
                    onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
                    aria-label="Back to top"
                    className={`w-11 h-11 rounded-full bg-ink-900/80 text-white shadow-lg backdrop-blur flex items-center justify-center hover:bg-ink-900 transition-all duration-300 ${showTop ? 'opacity-100' : 'opacity-0 pointer-events-none'}`}
                >
                    <Icon name="chevronUp" strokeWidth={2} />
                </button>
            </div>

            {/* Right: spin, assistant, offers, call, messenger, WhatsApp */}
            {anyRight && (
                <div ref={stackRef} className={`fixed right-5 z-50 flex flex-col items-center gap-3 ${rightPos}`}>
                    <div
                        id="floating-contact"
                        className={`flex-col items-center gap-3 md:flex ${folded && !open ? 'hidden' : 'flex'} ${folded ? 'pop-in' : ''}`}
                    >
                    <SpinFab />
                    {ai && (
                        /* The panel is the ai-chat partial (outside #app, so it
                           survives Inertia navigations); this only opens it. */
                        <button
                            type="button"
                            onClick={() => window.NoyChat?.toggle()}
                            className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-ink-900 text-white shadow-lg hover:scale-105 transition p-3.5"
                            title={ai.label}
                            aria-label={ai.label}
                            data-noy-chat-launcher
                        >
                            <Icon name="chat" className="w-full h-full" strokeWidth={1.8} />
                        </button>
                    )}
                    {offers && (
                        <div className="relative" ref={offersRef}>
                            <button
                                type="button"
                                onClick={() => setOffersOpen(!offersOpen)}
                                className="relative flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-gold-600 text-white shadow-lg hover:scale-105 transition p-3.5"
                                title="Your exclusive offers"
                                aria-label={`Your exclusive offers (${offers.badge})`}
                                aria-expanded={offersOpen}
                            >
                                <Icon name="gift" className="w-full h-full" strokeWidth={1.8} />
                                <span aria-hidden="true" className="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 flex items-center justify-center rounded-full bg-danger-600 text-white text-[11px] font-semibold">{offers.badge}</span>
                            </button>
                            {offersOpen && (
                                <div className="absolute bottom-16 right-0 w-72 max-h-[70vh] overflow-y-auto rounded-xl bg-white shadow-2xl border border-ink-100 p-4">
                                    <div className="flex items-center justify-between mb-2">
                                        <p className="font-semibold text-sm flex items-center gap-1.5"><Icon name="gift" className="w-4 h-4 shrink-0" /> Your exclusive offers</p>
                                        <button type="button" onClick={() => setOffersOpen(false)} aria-label="Close offers" className="text-ink-700/40 hover:text-ink-900 text-lg leading-none">×</button>
                                    </div>
                                    {offers.member && (
                                        <div className="rounded-lg border border-gold-300 bg-gold-600 text-white p-2.5 mb-2">
                                            <p className="text-sm font-medium flex items-center gap-1.5"><Icon name="diamond" className="w-4 h-4 shrink-0" /> {offers.member.percent}% member discount</p>
                                            {offers.member.capped ? (
                                                <p className="text-[11px] text-white/85 mt-0.5">
                                                    {offers.member.remaining > 0
                                                        ? `${offers.member.remaining} of ${offers.member.max} uses left${offers.member.resets ? ` · resets ${offers.member.resets}` : ''}`
                                                        : `Used up${offers.member.resets ? ` · resets ${offers.member.resets}` : ''}`}
                                                </p>
                                            ) : (
                                                <p className="text-[11px] text-white/85 mt-0.5">Auto-applied on every order</p>
                                            )}
                                        </div>
                                    )}
                                    <div className="space-y-2">
                                        {offers.items.map((o, i) => (
                                            <div key={i} className="rounded-lg border border-gold-200 bg-gold-50/60 p-2.5">
                                                <p className="text-sm font-medium">{o.title}</p>
                                                <span className="inline-block badge bg-gold-600 text-white text-[10px] mt-0.5">{o.reward}</span>
                                                {o.scope && <span className="text-[10px] text-ink-700/70 ml-1">· {o.scope}</span>}
                                                {o.message && <p className="text-xs text-ink-700/70 italic mt-1">{o.message}</p>}
                                                <p className="text-[11px] text-success-700 mt-1">✓ Auto-applied at checkout{o.until ? ` · until ${o.until}` : ''}</p>
                                            </div>
                                        ))}
                                    </div>
                                    <SmartLink href={urls.shop || '/shop'} className="btn-primary w-full text-sm mt-3 block text-center">Shop now</SmartLink>
                                </div>
                            )}
                        </div>
                    )}
                    {floats.call && (
                        <a href={`tel:${floats.call.replace(/[^0-9+]/g, '')}`} className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-gold-600 text-white shadow-lg hover:scale-105 transition p-3.5" title="Call us now" aria-label="Call us now">
                            <Phone className="w-full h-full" />
                        </a>
                    )}
                    {floats.messenger && (
                        <a href={floats.messenger} target="_blank" rel="noopener" className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-[#0084FF] text-white shadow-lg hover:scale-105 transition p-3" title="Message us" aria-label="Message us on Messenger">
                            <Messenger className="w-full h-full" />
                        </a>
                    )}
                    {floats.whatsapp && (
                        <a href={`https://wa.me/${floats.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener" className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:scale-105 transition p-3" title="Order on WhatsApp" aria-label="Order on WhatsApp">
                            <WhatsApp className="w-full h-full" />
                        </a>
                    )}
                    </div>

                    {/* The phone launcher: a plus that turns into a cross. */}
                    {folded && (
                        <button
                            type="button"
                            onClick={() => setOpen((o) => !o)}
                            aria-expanded={open}
                            aria-controls="floating-contact"
                            aria-label={open ? 'Close' : `Chat, offers and more (${rightCount})`}
                            title={open ? 'Close' : 'Chat, offers and more'}
                            className="relative md:hidden flex h-12 w-12 items-center justify-center rounded-full bg-ink-900 text-white shadow-lg ring-2 ring-gold-400 transition"
                        >
                            <span aria-hidden="true" className={`relative block h-5 w-5 transition-transform duration-300 ${open ? 'rotate-45' : ''}`}>
                                <span className="absolute left-1/2 top-0 h-5 w-[2px] -translate-x-1/2 rounded bg-white" />
                                <span className="absolute top-1/2 left-0 h-[2px] w-5 -translate-y-1/2 rounded bg-white" />
                            </span>
                            {!open && spinFab && (
                                <span aria-hidden="true" className="absolute -top-1 -right-1 rounded-full bg-danger-600 px-1.5 text-[10px] font-bold leading-5 text-white">WIN</span>
                            )}
                        </button>
                    )}
                </div>
            )}
        </>
    );
}
