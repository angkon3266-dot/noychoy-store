import { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import SmartLink from '../Shared/SmartLink';
import Icon, { WhatsApp } from '../Shared/Icons';
import { useCart } from '../Shared/CartContext';
import { fetchJson } from '../Shared/format';

/*
 * The wishlist (Nov 2026) — for everyone, since guests can love a piece too;
 * until now the list only showed up for members, under My account.
 *
 * Yours: share it by link (WhatsApp first — how this store's customers talk),
 * so the person buying the gift sees exactly what you want. Someone else's
 * (/wishlist/{code}): read-only, with one button to put the lot in the bag.
 */
const shell = 'mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-10';

const needsSize = (piece) => !!piece.sizes && piece.sizes.values.length > 0;

/** The cart line for a piece (useCart().addSet), or null while a size is missing. */
function cartItem(piece, sizeValue) {
    if (!piece.available || piece.needs_page) return null;
    if (needsSize(piece)) {
        const v = piece.sizes.values.find((s) => s.value === sizeValue && s.in_stock);
        return v ? { id: piece.id, variantId: v.variant_id, price: v.price, contentId: `prod-${piece.id}-var-${v.variant_id}` } : null;
    }
    return { id: piece.id, price: piece.price, contentId: piece.content_id };
}

export default function Wishlist({ shared, cards, pieces, shareUrl, customerName }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const store = props.chrome?.storeName || 'NoyChoy';
    const { addSet } = useCart();

    const [sizes, setSizes] = useState({});
    const [link, setLink] = useState(null);
    const [name, setName] = useState(customerName || '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [copied, setCopied] = useState(false);

    const byId = useMemo(() => Object.fromEntries(pieces.map((p) => [p.id, p])), [pieces]);
    const sized = pieces.filter((p) => p.available && needsSize(p));
    const items = pieces.map((p) => cartItem(p, sizes[p.id])).filter(Boolean);
    const missingSize = sized.filter((p) => !sizes[p.id]).length;
    const buyable = pieces.filter((p) => p.available && !p.needs_page).length;

    const makeLink = async () => {
        setBusy(true);
        setError(null);
        try {
            const body = new FormData();
            body.append('name', name);
            const res = await fetchJson(shareUrl, { method: 'POST', body });
            setLink(res.url);
            return res.url;
        } catch (e) {
            setError('Could not make the link just now — love a piece first, then try again.');
            return null;
        } finally {
            setBusy(false);
        }
    };

    const shareText = (url) => `My ${store} wishlist 💛 ${url}`;

    const shareNative = async () => {
        const url = link || await makeLink();
        if (!url) return;
        if (navigator.share) {
            try { await navigator.share({ title: `My ${store} wishlist`, text: shareText(url), url }); } catch (e) { /* dismissed */ }
        }
    };

    const copy = async () => {
        const url = link || await makeLink();
        if (!url) return;
        try { await navigator.clipboard.writeText(url); } catch (e) { /* unavailable */ }
        setCopied(true);
        setTimeout(() => setCopied(false), 1600);
    };

    const whatsapp = async () => {
        // Opened synchronously when we already have the link; otherwise the
        // popup blocker would eat a window opened after an await.
        if (link) {
            window.open(`https://wa.me/?text=${encodeURIComponent(shareText(link))}`, '_blank', 'noopener');
            return;
        }
        const w = window.open('', '_blank');
        const url = await makeLink();
        if (url && w) w.location.href = `https://wa.me/?text=${encodeURIComponent(shareText(url))}`;
        else w?.close();
    };

    return (
        <div className={`${shell} pb-10`}>
            <div className="pt-8 lg:pt-14 pb-6 lg:pb-10">
                <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />{shared ? 'A wishlist for you' : 'Saved for later'}</p>
                <h1 className="display mt-4 text-[2.4rem] sm:text-6xl lg:text-[4.2rem] leading-[1.05]">{shared ? `${shared.owner ? `${shared.owner}’s` : 'Their'} wishlist` : 'Your wishlist'}</h1>
                <p className="mt-4 max-w-2xl text-[15px] leading-relaxed text-ink-700/80">
                    {shared
                        ? `Every piece ${shared.owner || 'they'} would love. Pick one, or put the lot in the bag — it arrives gift-ready, and you pay on delivery.`
                        : 'Everything you have loved, in one place. Share it with whoever is buying the gift — they see exactly what you want.'}
                </p>
            </div>

            {cards.length === 0 ? (
                <div className="rounded-[28px] border border-ink-900/[0.08] bg-mist-50 p-10 text-center">
                    <Icon name="heart" className="w-10 h-10 mx-auto text-ink-900/25" />
                    <p className="mt-4 text-ink-800">{shared ? 'This wishlist is empty for now.' : 'Nothing loved yet.'}</p>
                    {!shared && <p className="mt-1 text-sm text-ink-700/70">Tap the heart on any piece to save it here.</p>}
                    <SmartLink href={urls.shop || '/shop'} className="btn-primary inline-flex mt-6">Browse the collection</SmartLink>
                </div>
            ) : (
                <>
                    {!shared && (
                        <section className="mb-10 rounded-[28px] bg-ink-900 text-white p-6 sm:p-8 lg:flex items-center justify-between gap-10">
                            <div className="max-w-lg">
                                <h2 className="display !text-white text-3xl">Share your wishlist</h2>
                                <p className="mt-2 text-sm text-white/70">A birthday, Eid, an anniversary coming up? Send your list and let them choose.</p>
                                <label className="mt-5 block max-w-xs">
                                    <span className="block text-[11px] uppercase tracking-[0.18em] text-white/50 mb-1.5">Your first name (shown on the list)</span>
                                    <input value={name} onChange={(e) => { setName(e.target.value); setLink(null); }} maxLength={60} placeholder="e.g. Nusrat" className="w-full rounded-full bg-white/10 border border-white/20 px-4 h-11 text-sm text-white placeholder-white/40 focus:outline-none focus:border-white" />
                                </label>
                            </div>
                            <div className="mt-6 lg:mt-0 flex flex-col sm:flex-row gap-2.5 lg:min-w-[22rem]">
                                <button type="button" onClick={whatsapp} disabled={busy} className="inline-flex items-center justify-center gap-2 rounded-full bg-[#25D366] px-5 min-h-12 text-sm font-semibold text-ink-900 hover:brightness-95 disabled:opacity-60">
                                    <WhatsApp /> Send on WhatsApp
                                </button>
                                <button type="button" onClick={typeof navigator !== 'undefined' && navigator.share ? shareNative : copy} disabled={busy} className="inline-flex items-center justify-center gap-2 rounded-full bg-white px-5 min-h-12 text-sm font-semibold text-ink-900 hover:bg-mist-100 disabled:opacity-60">
                                    {copied ? 'Link copied ✓' : (typeof navigator !== 'undefined' && navigator.share ? 'Share…' : 'Copy link')}
                                </button>
                            </div>
                            {(link || error) && (
                                <p className="mt-4 lg:mt-0 text-xs text-white/60 break-all lg:hidden" aria-live="polite">{error || link}</p>
                            )}
                        </section>
                    )}
                    {!shared && (link || error) && <p className="hidden lg:block -mt-7 mb-8 text-xs text-ink-700/60 break-all" aria-live="polite">{error || link}</p>}

                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-3 sm:gap-x-5 gap-y-10">
                        {cards.map((c) => (
                            <div key={c.id}>
                                <ProductCard product={c} />
                                {byId[c.id] && needsSize(byId[c.id]) && byId[c.id].available && (
                                    <div className="mt-3">
                                        <p className="text-[11px] uppercase tracking-[0.14em] text-ink-700/60 mb-1.5">{byId[c.id].sizes.name}</p>
                                        <SizeChips piece={byId[c.id]} value={sizes[c.id]} onChange={(v) => setSizes((s) => ({ ...s, [c.id]: v }))} />
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>

                    {buyable > 1 && (
                        <div className="sticky bottom-20 lg:bottom-6 z-30 mt-10 flex justify-center">
                            <div className="rounded-full border border-ink-900/10 bg-white/90 backdrop-blur px-3 py-2 shadow-xl flex items-center gap-3">
                                <span className="pl-3 text-sm text-ink-800">
                                    {missingSize > 0 ? `Choose ${missingSize === 1 ? 'a size' : `${missingSize} sizes`} to add every piece` : `${items.length} pieces`}
                                </span>
                                <button type="button" onClick={() => addSet(items)} disabled={items.length === 0} className="btn-primary !min-h-11 !px-5 text-sm disabled:opacity-50">
                                    Add {missingSize > 0 ? 'the rest' : 'all'} to bag
                                </button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

/** A compact size chooser for one piece. */
function SizeChips({ piece, value, onChange }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={`${piece.sizes.name} for ${piece.name}`}>
            {piece.sizes.values.map((s) => (
                <button
                    key={s.value}
                    type="button"
                    disabled={!s.in_stock}
                    onClick={() => onChange(value === s.value ? null : s.value)}
                    aria-pressed={value === s.value}
                    className={`rounded-full border min-h-8 px-3 text-xs transition-colors duration-300 ${value === s.value ? 'border-ink-900 bg-ink-900 text-white' : 'border-ink-900/15 text-ink-900 hover:border-ink-900'} ${!s.in_stock ? 'opacity-40 line-through cursor-not-allowed' : ''}`}
                >
                    {s.value}
                </button>
            ))}
        </div>
    );
}

Wishlist.layout = (page) => <Layout>{page}</Layout>;
