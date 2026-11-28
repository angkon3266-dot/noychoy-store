import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useCart } from './CartContext';
import { fetchJson } from './format';
import SmartLink from './SmartLink';
import Icon from './Icons';
import { trapTab } from './focusTrap';

// The "Choose" pop-up (owner, Nov 2026). A piece with options — a ring size,
// a colour — used to send her to its page from a card, the homepage buy block
// or the bag's "Pairs beautifully with" row. Now it opens this sheet over the
// page instead: pick the option, Add to bag, and carry on where she was.
//
// One sheet for the whole storefront, owned by <QuickPickHost> in the Layout;
// anything opens it with openQuickPick(card). The card's own name, photo and
// price show at once while the options load (CatalogController::options →
// QuickPickData). If they cannot load, the sheet offers the product page.

const OPEN_EVENT = 'quick-pick:open';

/** The options endpoint for a card: its own options_url, else /product/{slug}/options. */
function optionsUrl(card) {
    if (card.options_url) return card.options_url;
    try {
        const u = new URL(card.url, window.location.origin);
        return `${u.pathname.replace(/\/$/, '')}/options`;
    } catch (e) {
        return null;
    }
}

/** Open the pop-up for a card ({ name, url, thumb, price_text, options_url? }). */
export function openQuickPick(card) {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT, { detail: { card, trigger: document.activeElement } }));
}

export function QuickPickHost() {
    const { url: pageUrl } = usePage();
    const { add } = useCart();
    const [card, setCard] = useState(null);
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);
    const [selected, setSelected] = useState({});
    const [adding, setAdding] = useState(false);
    const [shown, setShown] = useState(false);
    const panelRef = useRef(null);
    const trigger = useRef(null);
    const loadId = useRef(0);

    const close = useCallback(() => {
        setShown(false);
        setCard(null);
        setData(null);
        setFailed(false);
        setSelected({});
        const back = trigger.current;
        trigger.current = null;
        // Back to the button that opened it, if it is still on the page.
        if (back && document.contains(back)) back.focus?.();
    }, []);

    useEffect(() => {
        const open = async (e) => {
            const next = e.detail?.card;
            if (!next) return;
            trigger.current = e.detail.trigger || null;
            const id = ++loadId.current;
            setCard(next);
            setData(null);
            setFailed(false);
            setSelected({});
            requestAnimationFrame(() => setShown(true));

            const endpoint = optionsUrl(next);
            try {
                if (!endpoint) throw new Error('no endpoint');
                const res = await fetchJson(endpoint);
                if (id !== loadId.current) return;
                // A value that is the only one of its option is chosen already.
                const pre = {};
                (res.options || []).forEach((o) => { if (o.values.length === 1) pre[o.name] = o.values[0]; });
                setSelected(pre);
                setData(res);
            } catch (err) {
                if (id === loadId.current) setFailed(true);
            }
        };
        window.addEventListener(OPEN_EVENT, open);
        return () => window.removeEventListener(OPEN_EVENT, open);
    }, []);

    // A page change closes it (a link inside it, or the browser's back button).
    useEffect(() => { if (card) close(); }, [pageUrl]); // eslint-disable-line react-hooks/exhaustive-deps

    // Escape closes the sheet only — not the bag drawer it may sit over, which
    // listens on window too; the capture phase reaches this first.
    useEffect(() => {
        if (!card) return undefined;
        const esc = (e) => {
            if (e.key !== 'Escape') return;
            e.stopPropagation();
            close();
        };
        window.addEventListener('keydown', esc, true);
        panelRef.current?.focus();
        return () => window.removeEventListener('keydown', esc, true);
    }, [card, close]);

    const options = data?.options || [];
    const variants = data?.variants || [];

    const matched = useMemo(() => {
        if (!data?.has_variants) return null;
        if (options.some((o) => !selected[o.name])) return null;
        return variants.find((v) => options.every((o) => String(v.attrs[o.name]) === String(selected[o.name]))) || null;
    }, [data, options, variants, selected]);

    if (!card) return null;

    const valueInStock = (name, value) => variants.some((v) => String(v.attrs[name]) === String(value) && v.in_stock);
    const priced = matched || data?.from || null;
    const prices = new Set(variants.map((v) => v.price));
    const showFrom = !matched && prices.size > 1;
    const image = matched?.image || data?.image || card.thumb450 || card.thumb;
    const name = data?.name || card.name;
    const productUrl = data?.url || card.url;
    const missing = options.filter((o) => !selected[o.name]).map((o) => o.name.toLowerCase());
    const soldOutCombo = matched && !matched.in_stock;
    const simple = data && !data.has_variants;
    const canAdd = !!data && data.available !== false && (simple || (matched && matched.in_stock));

    const addToBag = async () => {
        if (!canAdd || adding) return;
        setAdding(true);
        const line = simple ? null : matched;
        const ok = await add(data.add_url, { variant_id: line ? line.id : '', qty: 1 }, {
            contentId: line ? line.content_id : data.content_id,
            name: data.name,
            value: line ? line.price : data.from?.price,
        });
        setAdding(false);
        if (ok) close();
    };

    let label = data?.preorder ? 'Add pre-order to bag' : 'Add to bag';
    if (data && !simple && missing.length) label = `Choose ${missing.join(' & ')}`;
    if (soldOutCombo) label = 'Sold out';
    if (data && data.available === false) label = 'Sold out';

    return (
        <div className="fixed inset-0 z-[70]" role="presentation">
            <div
                className={`absolute inset-0 bg-ink-900/40 backdrop-blur-[3px] transition-opacity duration-300 ${shown ? 'opacity-100' : 'opacity-0'}`}
                onClick={close}
            />
            <div
                ref={panelRef}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-label={`Choose options for ${name}`}
                onKeyDown={(e) => trapTab(e, panelRef.current)}
                className={`absolute inset-x-0 bottom-0 sm:inset-auto sm:left-1/2 sm:top-1/2 sm:w-[440px] max-h-[88vh] overflow-y-auto bg-white rounded-t-[28px] sm:rounded-[28px] shadow-[0_40px_80px_-30px_rgb(0_0_0/0.5)] outline-none transition duration-300 ease-[var(--ease-lux)] ${shown ? 'translate-y-0 opacity-100 sm:-translate-x-1/2 sm:-translate-y-1/2' : 'translate-y-full sm:translate-y-[-46%] sm:-translate-x-1/2 sm:opacity-0'}`}
            >
                <div className="sm:hidden mx-auto mt-2.5 h-1 w-10 rounded-full bg-ink-900/15" aria-hidden="true" />
                <div className="flex gap-4 p-5 pb-4">
                    <span className="w-24 h-[120px] shrink-0 overflow-hidden rounded-2xl bg-mist-100">
                        {image && <img src={image} alt="" className="w-full h-full object-cover" />}
                    </span>
                    <div className="flex-1 min-w-0 pr-8">
                        <p className="text-[15px] leading-snug text-ink-900 line-clamp-3">{name}</p>
                        {priced ? (
                            <p className="mt-1.5 flex items-baseline flex-wrap gap-x-1.5">
                                {priced.member_text ? (
                                    <>
                                        <span className="text-[16px] font-semibold text-gold-700">{showFrom ? 'From ' : ''}{priced.member_text}</span>
                                        <span className="text-xs text-ink-500 line-through">{priced.price_text}</span>
                                    </>
                                ) : (
                                    <>
                                        <span className={`text-[16px] font-semibold ${priced.compare_text ? 'text-danger-600' : 'text-ink-900'}`}>{showFrom ? 'From ' : ''}{priced.price_text}</span>
                                        {priced.compare_text && <span className="text-xs text-ink-500 line-through">{priced.compare_text}</span>}
                                    </>
                                )}
                            </p>
                        ) : (
                            card.price_text && <p className="mt-1.5 text-[16px] font-semibold text-ink-900">{card.price_text}</p>
                        )}
                        {data?.member_pct && <p className="mt-0.5 text-[11px] text-gold-700">Member −{data.member_pct}%</p>}
                    </div>
                    <button
                        type="button"
                        onClick={close}
                        aria-label="Close"
                        className="absolute top-4 right-4 grid place-items-center w-9 h-9 rounded-full bg-mist-100 text-ink-900 hover:bg-ink-900 hover:text-white transition-colors"
                    >
                        <Icon name="close" className="w-4 h-4" strokeWidth={1.8} />
                    </button>
                </div>

                <div className="px-5 pb-5">
                    {!data && !failed && (
                        <div className="space-y-3 py-2" aria-live="polite" aria-busy="true">
                            <span className="sr-only">Loading options…</span>
                            <div className="h-4 w-24 rounded bg-mist-100 animate-pulse" />
                            <div className="flex gap-2">
                                {[0, 1, 2, 3].map((i) => <div key={i} className="h-11 w-14 rounded-full bg-mist-100 animate-pulse" />)}
                            </div>
                        </div>
                    )}

                    {failed && (
                        <div className="py-2">
                            <p className="text-sm text-ink-700/80">The options did not load just now.</p>
                            <SmartLink href={productUrl} onClick={close} className="btn-primary w-full mt-4">Choose on the product page</SmartLink>
                        </div>
                    )}

                    {data && (
                        <>
                            {options.map((o) => (
                                <div key={o.name} className="mt-1 mb-4">
                                    <p className="label">
                                        {o.name}: <span className="text-ink-700/70 font-normal">{selected[o.name] || 'Choose'}</span>
                                    </p>
                                    <div className="flex flex-wrap gap-2" role="group" aria-label={o.name}>
                                        {o.values.map((val) => {
                                            const inStock = valueInStock(o.name, val);
                                            const isSel = String(selected[o.name]) === String(val);
                                            return (
                                                <button
                                                    key={val}
                                                    type="button"
                                                    disabled={!inStock}
                                                    aria-pressed={isSel}
                                                    onClick={() => setSelected((prev) => ({ ...prev, [o.name]: val }))}
                                                    className={`rounded-full border min-h-11 min-w-11 px-4 text-sm transition-colors duration-200 ${isSel ? 'border-ink-900 bg-ink-900 text-white' : 'border-ink-900/15 text-ink-900 hover:border-ink-900'} ${!inStock ? 'opacity-40 line-through cursor-not-allowed' : ''}`}
                                                >
                                                    {val}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                            {soldOutCombo && <p className="-mt-1 mb-3 text-xs text-danger-600 font-medium">This combination is sold out.</p>}

                            <button
                                type="button"
                                onClick={addToBag}
                                disabled={!canAdd || adding}
                                className="btn-primary w-full !min-h-14 mt-1 disabled:opacity-50"
                            >
                                {adding
                                    ? <span className="w-4 h-4 rounded-full border-2 border-white/40 border-t-white animate-spin" aria-label="Adding" />
                                    : label}
                            </button>
                            <SmartLink href={productUrl} onClick={close} className="mt-3 flex items-center justify-center gap-1 text-[13px] text-ink-700/80 hover:text-ink-900">
                                See full details &amp; size guide
                                <Icon name="chevronRight" className="w-3.5 h-3.5" strokeWidth={2} />
                            </SmartLink>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
