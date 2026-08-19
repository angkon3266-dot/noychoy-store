import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon, { Messenger, Phone, WhatsApp } from '../Icons';
import ShareButton from '../ShareButton';
import SmartLink from '../SmartLink';

// Floating stacks: share + back-to-top (left), member offers / call /
// messenger / WhatsApp (right).
export default function FloatingStack() {
    const { props } = usePage();
    const chrome = props.chrome || {};
    const floats = chrome.floats || {};
    const offers = chrome.offers;
    const urls = chrome.urls || {};
    const [showTop, setShowTop] = useState(false);
    const [offersOpen, setOffersOpen] = useState(false);
    const offersRef = useRef(null);

    useEffect(() => {
        const onScroll = () => setShowTop(window.scrollY > 600);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        const close = (e) => {
            if (offersRef.current && !offersRef.current.contains(e.target)) setOffersOpen(false);
        };
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const anyRight = offers || floats.call || floats.messenger || floats.whatsapp;

    return (
        <>
            {/* Left: share + back-to-top */}
            <div className="fixed left-5 z-40 flex flex-col items-center gap-3 bottom-20 md:bottom-5">
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

            {/* Right: offers, call, messenger, WhatsApp */}
            {anyRight && (
                <div className="fixed right-5 z-50 flex flex-col items-center gap-3 bottom-[calc(4.75rem_+_env(safe-area-inset-bottom))] md:bottom-5">
                    {offers && (
                        <div className="relative" ref={offersRef}>
                            <button
                                type="button"
                                onClick={() => setOffersOpen(!offersOpen)}
                                className="relative flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-gold-600 text-white shadow-lg hover:scale-105 transition p-3.5"
                                title="Your exclusive offers"
                            >
                                <Icon name="gift" className="w-full h-full" strokeWidth={1.8} />
                                <span className="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 flex items-center justify-center rounded-full bg-red-600 text-white text-[11px] font-semibold">{offers.badge}</span>
                            </button>
                            {offersOpen && (
                                <div className="absolute bottom-16 right-0 w-72 max-h-[70vh] overflow-y-auto rounded-xl bg-white shadow-2xl border border-ink-100 p-4">
                                    <div className="flex items-center justify-between mb-2">
                                        <p className="font-semibold text-sm flex items-center gap-1.5">🎁 Your exclusive offers</p>
                                        <button type="button" onClick={() => setOffersOpen(false)} className="text-ink-700/40 hover:text-ink-900 text-lg leading-none">×</button>
                                    </div>
                                    {offers.member && (
                                        <div className="rounded-lg border border-gold-300 bg-gold-600 text-white p-2.5 mb-2">
                                            <p className="text-sm font-medium">💎 {offers.member.percent}% member discount</p>
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
                                                {o.scope && <span className="text-[10px] text-ink-700/50 ml-1">· {o.scope}</span>}
                                                {o.message && <p className="text-xs text-ink-700/70 italic mt-1">{o.message}</p>}
                                                <p className="text-[11px] text-green-700 mt-1">✓ Auto-applied at checkout{o.until ? ` · until ${o.until}` : ''}</p>
                                            </div>
                                        ))}
                                    </div>
                                    <SmartLink href={urls.shop || '/shop'} className="btn-primary w-full text-sm mt-3 block text-center">Shop now</SmartLink>
                                </div>
                            )}
                        </div>
                    )}
                    {floats.call && (
                        <a href={`tel:${floats.call.replace(/[^0-9+]/g, '')}`} className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-gold-600 text-white shadow-lg hover:scale-105 transition p-3.5" title="Call us now">
                            <Phone className="w-full h-full" />
                        </a>
                    )}
                    {floats.messenger && (
                        <a href={floats.messenger} target="_blank" rel="noopener" className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-[#0084FF] text-white shadow-lg hover:scale-105 transition p-3" title="Message us">
                            <Messenger className="w-full h-full" />
                        </a>
                    )}
                    {floats.whatsapp && (
                        <a href={`https://wa.me/${floats.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener" className="flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg hover:scale-105 transition p-3" title="Order on WhatsApp">
                            <WhatsApp className="w-full h-full" />
                        </a>
                    )}
                </div>
            )}
        </>
    );
}
