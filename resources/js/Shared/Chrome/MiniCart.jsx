import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useCart } from '../CartContext';
import SmartLink from '../SmartLink';
import GiftLadderBar from '../GiftLadderBar';
import Icon from '../Icons';
import { trapTab } from '../focusTrap';
import { t } from '../i18n';
import OfferCountdown from '../OfferCountdown';

// Mini-cart slide-over — same /cart/mini data contract as the Alpine drawer.
export default function MiniCart() {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const { drawer, setDrawer, count, items, subtotalText, discountLines, hints, gift, couponNotice, freeShipping, deliverySavedText, remove, cartTrigger } = useCart();
    const panelRef = useRef(null);

    useEffect(() => {
        if (drawer) {
            panelRef.current?.focus();
        } else if (cartTrigger?.current) {
            // Back to the cart button that opened this, not the top of the page.
            cartTrigger.current.focus?.();
            cartTrigger.current = null;
        }
    }, [drawer, cartTrigger]);

    useEffect(() => {
        if (!drawer) return;
        const esc = (e) => e.key === 'Escape' && setDrawer(false);
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [drawer, setDrawer]);

    return (
        // aria-hidden alone was a violation while the subtree stayed
        // focusable: the drawer announced itself as hidden and was still
        // reachable by Tab on every page. `inert` is what makes the pair legal.
        <div className={`fixed inset-0 z-[60] ${drawer ? '' : 'pointer-events-none'}`} inert={!drawer} aria-hidden={!drawer}>
            <div
                className={`absolute inset-0 bg-ink-900/30 backdrop-blur-[3px] transition-opacity duration-500 ${drawer ? 'opacity-100' : 'opacity-0'}`}
                onClick={() => setDrawer(false)}
            />
            <div
                ref={panelRef}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-label="Your cart"
                onKeyDown={(e) => trapTab(e, panelRef.current)}
                className={`absolute right-0 top-0 sm:top-2 sm:right-2 sm:bottom-2 h-full sm:h-auto w-[88%] max-w-[420px] bg-white sm:rounded-[28px] shadow-[0_40px_80px_-30px_rgb(0_0_0/0.45)] flex flex-col outline-none transition-transform duration-500 ease-[var(--ease-lux)] ${drawer ? 'translate-x-0' : 'translate-x-[110%]'}`}
            >
                <div className="flex items-center justify-between px-6 pt-6 pb-5 border-b border-ink-900/[0.07]">
                    <h2 className="font-display text-2xl tracking-[-0.02em] text-ink-900">Your bag <span className="text-ink-900/35 tabular-nums">({count})</span></h2>
                    <button type="button" onClick={() => setDrawer(false)} aria-label="Close cart" className="grid place-items-center w-10 h-10 rounded-full bg-mist-100 text-ink-900 hover:bg-ink-900 hover:text-white transition-colors"><Icon name="close" className="w-4 h-4" strokeWidth={1.8} /></button>
                </div>
                <div className="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    {!items.length && (
                        <div className="text-center py-16">
                            <span className="mx-auto grid place-items-center w-16 h-16 rounded-full bg-mist-100 text-ink-900"><Icon name="bag" className="w-6 h-6" strokeWidth={1.4} /></span>
                            <p className="mt-5 font-display text-xl text-ink-900">Your bag is empty</p>
                            <p className="mt-1.5 text-sm text-ink-700/70">Pieces you add will wait for you here.</p>
                            <SmartLink href={urls.shop || '/shop'} onClick={() => setDrawer(false)} className="btn-primary mt-6">Start shopping</SmartLink>
                        </div>
                    )}
                    {items.map((item) => (
                        <div key={item.key} className="relative flex gap-3 group/ci">
                            <SmartLink href={item.url} onClick={() => setDrawer(false)} className="flex gap-3 flex-1 min-w-0 pr-6">
                                <span className="w-20 h-24 rounded-xl bg-mist-100 overflow-hidden shrink-0">
                                    {item.image && <img src={item.image} className="w-full h-full object-cover" alt="" />}
                                </span>
                                <span className="flex-1 min-w-0">
                                    <span className="block text-[14px] leading-snug text-ink-900 line-clamp-2">{item.name}</span>
                                    <span className="block text-xs text-ink-700/60 mt-1">Qty {item.qty}</span>
                                    <span className="block text-[14px] font-semibold text-ink-900 mt-1">{item.price_text}</span>
                                    {item.promo && (
                                        <span className="block text-[11px] text-success-700">
                                            {item.promo.label} · you save {item.promo.saving_text}
                                            {item.promo.ends && <OfferCountdown ends={item.promo.ends} className="ml-1 font-semibold text-danger-600" />}
                                        </span>
                                    )}
                                </span>
                            </SmartLink>
                            <button
                                type="button"
                                onClick={() => remove(item.key)}
                                className="absolute top-0 right-0 p-1 text-ink-700/40 hover:text-danger-600 transition"
                                title={`Remove ${item.name}`}
                                aria-label={`Remove ${item.name}`}
                            >
                                <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    ))}
                </div>
                {items.length > 0 && (
                    <div className="border-t border-ink-900/[0.07] px-6 py-5 space-y-3 bg-mist-50 sm:rounded-b-[28px]">
                        <GiftLadderBar gift={gift} compact />
                        <div className="flex justify-between items-baseline"><span className="text-sm text-ink-700/70">Subtotal</span><span className="text-lg font-semibold text-ink-900">{subtotalText}</span></div>
                        {discountLines.map((d) => (
                            <div key={d.label} className="flex justify-between text-sm text-success-700"><span>{d.label}</span><span>−{d.amount_text}</span></div>
                        ))}
                        {freeShipping && (
                            <div className="flex justify-between text-sm text-success-700"><span>Free delivery</span><span className="inline-flex items-center gap-1">{deliverySavedText ? `−${deliverySavedText}+` : ''}<Icon name="check" className="w-4 h-4" /></span></div>
                        )}
                        {hints.map((h) => (
                            <div key={h} className="rounded-xl bg-warning-50 border border-warning-200 text-warning-800 px-3 py-2 text-xs flex items-center gap-1.5"><Icon name="gift" className="w-3.5 h-3.5 shrink-0" />{h}</div>
                        ))}
                        {couponNotice && (
                            <div className="rounded-xl bg-mist-50 border border-ink-900/10 text-ink-800 px-3 py-2 text-xs flex items-start gap-1.5">
                                <Icon name="bulb" className="w-3.5 h-3.5 shrink-0 mt-[1px] text-gold-700" />
                                <span>{couponNotice}</span>
                            </div>
                        )}
                        {props.customer && props.chrome?.membership?.tier?.next && (
                            <p className="text-xs text-ink-800 rounded-xl bg-mist-50 border border-ink-900/[0.06] px-3 py-2">
                                <Icon name="medal" className="w-3.5 h-3.5 inline -mt-0.5 mr-1 text-gold-700" />
                                {props.chrome.membership.tier.toNextSpendText ? `${props.chrome.membership.tier.toNextSpendText} more in orders` : `${props.chrome.membership.tier.toNextPoints} points`} to reach <strong>{props.chrome.membership.tier.next}</strong>.
                            </p>
                        )}
                        {!props.customer && props.chrome?.membership?.pct && (
                            <a href={urls.register} onClick={() => setDrawer(false)} className="block rounded-md border border-ink-900/10 bg-mist-50 px-3 py-2 text-xs text-ink-800 hover:bg-mist-100" lang={props.chrome.lang}>
                                <Icon name="diamond" className="w-3.5 h-3.5 inline -mt-0.5 mr-1 text-gold-700" />
                                {t(props.chrome.lang, 'minicart.save', { pct: props.chrome.membership.pct })}
                                {props.chrome.membership.pointsPer1000 ? t(props.chrome.lang, 'minicart.points', { points: props.chrome.membership.pointsPer1000 }) : ''}
                                {' — '}<span className="underline font-medium">{t(props.chrome.lang, 'minicart.join')}</span>
                            </a>
                        )}
                        <div className="grid grid-cols-2 gap-2 pt-1">
                            <Link href={urls.cart || '/cart'} onClick={() => setDrawer(false)} className="btn-outline w-full">View bag</Link>
                            <SmartLink href={urls.checkout || '/checkout'} onClick={() => setDrawer(false)} className="btn-primary w-full">Checkout</SmartLink>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
