import { useEffect, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import { fetchJson, money } from '../Shared/format';
import Icon from '../Shared/Icons';
import MemberPill from '../Shared/MemberPill';
import { useCart } from '../Shared/CartContext';
import OfferCountdown from '../Shared/OfferCountdown';

// Mirrors app/helpers.php bd_phone() so the client and the server agree on
// what "the same number" is — and so "017 1234 5678" is not silently dropped.
const bdPhone = (v) => {
    let d = String(v || '').replace(/\D/g, '');
    if (d.startsWith('880')) d = d.slice(3);
    if (d.length === 10 && d.startsWith('1')) d = '0' + d;
    return d;
};

// COD checkout — the money page. Faithful port of shop/checkout.blade.php:
// live shipping-zone totals, abandoned-cart lead capture on phone blur,
// loyalty point redemption, and the InitiateCheckout pixel event.
export default function Checkout({ items, summary, prefill, loyalty, registerPct, ic, urls, coupon, freeShipping, gift }) {
    const { props } = usePage();
    const chromeUrls = props.chrome?.urls || {};
    const errors = props.errors || {};
    // Per-field messages, the way Account/Profile.jsx:22 already does it — the
    // middleware shares MessageBag::getMessages(), so each value is an array.
    // The old code flattened these into one banner, throwing away which field
    // each message belonged to.
    const err = (k) => errors[k]?.[0];
    // DOM order of the form's fields — decides which one gets focus on failure.
    const FIELD_ORDER = ['name', 'phone', 'address', 'is_inside_dhaka', 'is_gift', 'card_message', 'notes'];
    // `email`, `district` and `area` are validated server-side but have no
    // input here; without this they would fail silently.
    const unmapped = Object.keys(errors).filter((k) => !FIELD_ORDER.includes(k));
    const [errorNonce, setErrorNonce] = useState(0);
    // id + invalid flag + describedby, keeping any pre-existing helper id.
    const a11y = (k, help) => ({
        id: `co-${k}`,
        'aria-invalid': err(k) ? 'true' : undefined,
        'aria-describedby': [help, err(k) ? `co-${k}-error` : null].filter(Boolean).join(' ') || undefined,
    });
    // The value last stored, not a boolean: a customer who typos their number
    // and corrects it is the exact case abandoned-cart capture exists for, so a
    // materially different number must re-send. Identical ones do not.
    const leadSent = useRef('');
    const leadBusy = useRef(false);
    // Totals recomputed by the server once it knows who is checking out: a
    // coupon assigned to her phone applies the moment she finishes typing it,
    // rather than appearing out of nowhere on the confirmation screen. Null
    // until then, so the page starts from the props it was rendered with.
    const [live, setLive] = useState(null);
    const [couponBusy, setCouponBusy] = useState(false);
    // The order summary starts open (owner, 2026-09-19): the pieces, the
    // savings and the delivery charge are what she is agreeing to pay for, so
    // they are shown rather than tucked behind a tap. It was folded on
    // 2026-09-11 to de-clutter the page; the header still folds it for anyone
    // who wants the form alone.
    const [summaryOpen, setSummaryOpen] = useState(true);

    // Taking a piece out without leaving the checkout (owner, 2026-09-17).
    // The line goes through the same remove the mini-cart uses, so the header
    // count follows; then only the props that depend on the basket are
    // re-read. A partial reload tells the server this is the same checkout, so
    // it records no second checkout start and sends Meta no second
    // InitiateCheckout. Removing the last piece sends her back to the cart,
    // because the checkout redirects an empty basket there.
    const { remove: removeFromCart } = useCart();
    const [removing, setRemoving] = useState(null);
    const removeItem = async (key) => {
        if (!key || removing) return;
        setRemoving(key);
        await removeFromCart(key);
        router.reload({
            only: ['items', 'summary', 'coupon', 'freeShipping', 'gift', 'loyalty', 'cart', 'ladder', 'flash', 'errors'],
            preserveScroll: true,
            onSuccess: () => setLive(null),
            onFinish: () => setRemoving(null),
        });
    };
    // A coupon applied or rejected answers back into this card, so never leave
    // the verdict behind a fold the customer just closed by navigating.
    const flash = props.flash || {};
    useEffect(() => {
        if (flash.success || flash.error) setSummaryOpen(true);
    }, [flash.success, flash.error]);

    // Removing a coupon goes through Inertia: the server answers with
    // back() → this page re-renders with the new totals + a flash message.
    const removeCoupon = () => {
        setCouponBusy(true);
        router.delete(urls.couponRemove, { preserveScroll: true, onFinish: () => setCouponBusy(false) });
    };

    const form = useForm({
        name: prefill.name,
        phone: prefill.phone,
        address: prefill.address,
        area: prefill.area,
        is_inside_dhaka: prefill.inside ? '1' : '0',
        notes: '',
        is_gift: false,
        card_message: '',
    });

    // InitiateCheckout — same event id as the server's CAPI call (dedup).
    useEffect(() => {
        if (window.track) {
            window.track('InitiateCheckout', {
                content_ids: ic.contentIds,
                content_type: 'product',
                value: ic.value,
                currency: 'BDT',
                num_items: ic.numItems,
            }, { eventID: ic.eventId });
        }
    }, [ic.eventId]);

    const inside = form.data.is_inside_dhaka === '1';

    // The picker opens on Inside Dhaka (owner, 2026-09-23), so this is left
    // only with the returning customer whose saved address is outside Dhaka
    // and who then types a Dhaka one. She can still override, and the server
    // charges by the picker either way.
    const zoneTouched = useRef(false);
    useEffect(() => {
        if (zoneTouched.current) return;
        if (/\bdhaka\b/i.test(form.data.address) && form.data.is_inside_dhaka !== '1') form.setData('is_inside_dhaka', '1');
    }, [form.data.address]);
    // The server is the authority on free delivery (threshold, coupon, offer
    // or a per-customer perk) — mirror its verdict rather than re-deriving it.
    const view = live ? { ...summary, ...live } : summary;
    const freeShip = live ? live.freeShipping : freeShipping;
    const ship = freeShip ? 0 : (inside ? view.shipInside : view.shipOutside);
    const total = view.sub + ship;
    // What she is saving, and the price it came off (owner, 2026-09-19: the
    // original struck through beside the total, and the saving said). The
    // same sum as the header's "saved" badge (CartService::totalSaved): money
    // off the pieces, plus the delivery charge once delivery is free — at the
    // lower of the two rates, as the badge counts it, since the zone is not
    // asked for then.
    const deliverySaved = freeShip ? Math.min(view.shipInside, view.shipOutside) : 0;
    const saved = Math.max(0, (view.rawSubtotal ?? view.sub) - view.sub) + deliverySaved;
    const original = total + saved;
    // A whole taka at least: money() rounds, and a paisa of saving would
    // strike through the very figure printed beside it.
    const saving = saved >= 1;
    const wasPrice = saving && (
        <s className="mr-1 font-normal opacity-70"><span className="sr-only">was </span>{money(original)}</s>
    );
    // Pieces, not lines: "2 items" for one product bought twice.
    const itemCount = items.reduce((n, i) => n + i.qty, 0);

    // Capture the lead the moment a valid phone is typed — a COD order the
    // customer abandons is still a phone number the team can follow up.
    const captureLead = () => {
        const phone = bdPhone(form.data.phone);
        // App\Rules\BdPhone's pattern, applied after canonicalisation.
        if (!/^01[3-9]\d{8}$/.test(phone)) return;
        const name = (form.data.name || '').trim();
        const address = (form.data.address || '').trim();
        const area = (form.data.area || '').trim();
        const inDhaka = form.data.is_inside_dhaka === '1';
        // Every captured field belongs in the key. Leave one out and the
        // customer who types her phone (lead sent, latch set) and *then* her
        // address never sends the address — the key would not have changed.
        const key = `${phone}|${name}|${address}|${area}|${inDhaka}`;
        if (key === leadSent.current || leadBusy.current) return;
        leadBusy.current = true;
        // fetchJson throws on any non-2xx, so a 429 from the endpoint's own
        // throttle leaves leadSent untouched and the next blur retries. The old
        // bare fetch() set the latch before the request and never cleared it on
        // an HTTP-level failure.
        fetchJson(urls.lead, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            // A blank field is sent as null and the server leaves the stored
            // value alone, so an early name-blur cannot wipe a later address.
            body: JSON.stringify({
                phone, name: name || null, email: null,
                address: address || null,
                area: area || null,
                is_inside_dhaka: address || area ? inDhaka : null,
            }),
        })
            .then((res) => {
                leadSent.current = key;
                if (res?.summary) setLive(res.summary);
            })
            .catch(() => {})
            .finally(() => { leadBusy.current = false; });
    };

    // Send the customer straight to the first thing that needs fixing, rather
    // than making them hunt for it under a list at the top of the page.
    useEffect(() => {
        if (!errorNonce) return;
        const first = FIELD_ORDER.find((k) => errors[k]);
        const row = first ? document.querySelector(`[data-field="${first}"]`) : null;
        const input = first ? document.getElementById(`co-${first}`) : null;
        // No `behavior` key: app.css owns smooth scroll and already disables it
        // under prefers-reduced-motion.
        (row || input || document.getElementById('checkout-errors'))?.scrollIntoView({ block: 'center' });
        input?.focus({ preventScroll: true });
    }, [errorNonce]);

    const submit = (e) => {
        e.preventDefault();
        form.post(urls.store, {
            // Hold scroll only when we stay on this page; a placed order
            // redirects to the confirmation, which must open at the top.
            preserveScroll: (page) => Object.keys(page.props.errors || {}).length > 0,
            onError: () => {
                // By now the number may have been corrected — re-capture it.
                captureLead();
                setErrorNonce((n) => n + 1);
            },
        });
    };

    return (
        <div className="mx-auto max-w-2xl px-4 py-8">
            <h1 className="font-display text-3xl font-semibold mb-6">Checkout</h1>

            {Object.keys(errors).length > 0 && (
                <div id="checkout-errors" role="alert" tabIndex={-1} className="rounded-md bg-danger-50 border border-danger-200 text-danger-800 px-4 py-3 text-sm mb-6">
                    <p className="font-medium">Please check the highlighted {Object.keys(errors).length === 1 ? 'field' : 'fields'} below.</p>
                    {/* Anything with no input on this page still has to be shown. */}
                    {unmapped.length > 0 && (
                        <ul className="list-disc list-inside mt-1">
                            {unmapped.map((k) => <li key={k}>{err(k)}</li>)}
                        </ul>
                    )}
                </div>
            )}

            {/* One column, and the submit button sits four fields in: the
                fastest path to a COD order is name, number, address, thana,
                place order. Everything that is not needed to get there — the
                zone, the gift card, the note, the summary itself — lives below
                the button for whoever wants it. */}
            <form onSubmit={submit} className="space-y-6">
                <div className="card p-6 space-y-4">
                    <h2 className="font-display text-xl font-semibold">Delivery details</h2>

                    <div className="grid sm:grid-cols-2 gap-4">
                        <div data-field="name">
                            <label className="label" htmlFor="co-name">Full name *</label>
                            <input {...a11y('name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} onBlur={captureLead} className="input" required autoComplete="name" />
                            {err('name') && <p id="co-name-error" className="text-xs text-danger-600 mt-1">{err('name')}</p>}
                        </div>
                        <div data-field="phone">
                            {/* The phone gets the numeric keypad — this form is filled
                                one-thumbed on a phone, and a COD order lives or dies
                                on this field. */}
                            <label className="label" htmlFor="co-phone">Mobile number *</label>
                            <input
                                {...a11y('phone')}
                                type="tel"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                                onBlur={captureLead}
                                placeholder="01XXXXXXXXX"
                                className="input"
                                required
                                autoComplete="tel"
                                inputMode="numeric"
                            />
                            {err('phone') && <p id="co-phone-error" className="text-xs text-danger-600 mt-1">{err('phone')}</p>}
                        </div>
                    </div>
                    <div data-field="address">
                        <label className="label" htmlFor="co-address">Full address *</label>
                        <textarea {...a11y('address')} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} onBlur={captureLead} rows={2} className="input" required autoComplete="street-address" />
                        {err('address') && <p id="co-address-error" className="text-xs text-danger-600 mt-1">{err('address')}</p>}
                    </div>
                    {/* Free delivery leaves nothing to choose between: the zone
                        still travels with the order, inferred from the address
                        above. */}
                    {!freeShip && (
                    <div data-field="is_inside_dhaka">
                        <span className="label">Delivery zone</span>
                        <div className="flex gap-3" role="radiogroup" aria-label="Delivery zone">
                            <label className={`flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm ${inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'}`}>
                                <input id="co-is_inside_dhaka" aria-invalid={err('is_inside_dhaka') ? 'true' : undefined} type="radio" name="is_inside_dhaka" value="1" checked={inside} onChange={() => { zoneTouched.current = true; form.setData('is_inside_dhaka', '1'); }} className="sr-only" />
                                Inside Dhaka — ৳{view.shipInside}
                            </label>
                            <label className={`flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm ${!inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'}`}>
                                <input type="radio" name="is_inside_dhaka" value="0" checked={!inside} onChange={() => { zoneTouched.current = true; form.setData('is_inside_dhaka', '0'); }} className="sr-only" />
                                Outside Dhaka — ৳{view.shipOutside}
                            </label>
                        </div>
                        {/* The radios are sr-only, so the effect scrolls to the
                            [data-field] wrapper and focuses the input separately. */}
                        {err('is_inside_dhaka') && <p id="co-is_inside_dhaka-error" className="text-xs text-danger-600 mt-1">{err('is_inside_dhaka')}</p>}
                    </div>
                    )}

                    {/* The summary sits below the form, so the button carries
                        the total — nobody should have to scroll or open
                        anything to know what they are agreeing to pay. */}
                    <div className="pt-1">
                        <button type="submit" className="btn-primary w-full" disabled={form.processing}>
                            {form.processing ? 'Placing order…' : <>Place order · {wasPrice}{money(total)}</>}
                        </button>
                        {saving && (
                            <p className="mt-2 flex items-center justify-center gap-1.5 text-center text-sm font-semibold text-success-700" aria-live="polite">
                                <Icon name="tag" className="w-4 h-4 shrink-0" />
                                <span>You're saving {money(saved)}</span>
                            </p>
                        )}
                        <p className="mt-2 flex items-center justify-center gap-1.5 text-center text-xs text-ink-700/70">
                            <Icon name="cash" className="w-4 h-4 shrink-0" />
                            <span>Cash on delivery — no advance payment, we call to confirm.</span>
                        </p>
                    </div>

                    {/* Gift option. Every string here is editable in
                        Appearance → Gift orders, including the character
                        limit. The message is stored on the order
                        (card_message) and printed on the card the team packs. */}
                    {gift && (
                        <div className={`rounded-xl border p-4 transition-colors ${form.data.is_gift ? 'border-gold-400 bg-gold-50/60' : 'border-ink-100'}`}>
                            <label className="flex items-start gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_gift}
                                    onChange={(e) => form.setData('is_gift', e.target.checked)}
                                    className="mt-1 h-4 w-4 accent-gold-600"
                                />
                                <span>
                                    <span className="flex items-center gap-1.5 text-sm font-semibold"><Icon name="gift" className="w-4 h-4 shrink-0" />{gift.title}</span>
                                    <span className="block text-xs text-ink-700/70 mt-0.5">{gift.note}</span>
                                </span>
                            </label>
                            {form.data.is_gift && (
                                <div className="mt-3" data-field="card_message">
                                    <label className="label text-xs" htmlFor="co-card_message">{gift.messageLabel}</label>
                                    <textarea
                                        {...a11y('card_message')}
                                        value={form.data.card_message}
                                        onChange={(e) => form.setData('card_message', e.target.value.slice(0, gift.max))}
                                        rows={3}
                                        maxLength={gift.max}
                                        placeholder={gift.messagePlaceholder}
                                        className="input"
                                    />
                                    <div className="flex items-center justify-between mt-1 text-[11px] text-ink-700/70">
                                        <span>{gift.messageHelp}</span>
                                        <span className={form.data.card_message.length >= gift.max ? 'text-gold-700 font-medium' : ''}>
                                            {form.data.card_message.length}/{gift.max}
                                        </span>
                                    </div>
                                    {err('card_message') && <p id="co-card_message-error" className="text-xs text-danger-600 mt-1">{err('card_message')}</p>}
                                </div>
                            )}
                        </div>
                    )}

                    <div data-field="notes">
                        <label className="label" htmlFor="co-notes">Order note (optional)</label>
                        <textarea {...a11y('notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} className="input" />
                        {err('notes') && <p id="co-notes-error" className="text-xs text-danger-600 mt-1">{err('notes')}</p>}
                    </div>
                </div>

                {/* Order summary — open on arrival (owner, 2026-09-19). The
                    header keeps the two things that matter at a glance (how
                    many items and what it comes to) and still folds the
                    breakdown, the coupon box and the offer notices away. */}
                <div className="card overflow-hidden">
                    <h2>
                        <button
                            type="button"
                            onClick={() => setSummaryOpen((open) => !open)}
                            aria-expanded={summaryOpen}
                            aria-controls="order-summary"
                            className="w-full flex items-center justify-between gap-3 px-6 py-4 text-left"
                        >
                            <span className="font-display text-lg font-semibold">
                                Order summary
                                <span className="ml-2 font-sans text-xs font-normal text-ink-700/60">
                                    {itemCount} {itemCount === 1 ? 'item' : 'items'}
                                </span>
                            </span>
                            <span className="flex shrink-0 items-center gap-2">
                                <span className="font-semibold">{wasPrice}{money(total)}</span>
                                <Icon name="chevronDown" className={`w-4 h-4 text-ink-700/60 transition-transform ${summaryOpen ? 'rotate-180' : ''}`} />
                            </span>
                        </button>
                    </h2>

                    {summaryOpen && (
                        <div id="order-summary" className="border-t border-ink-100 px-6 pt-4 pb-6">
                            {flash.success && (
                                <div className="mb-3 rounded-md bg-success-50 border border-success-200 text-success-800 px-3 py-2 text-sm">{flash.success}</div>
                            )}
                            {flash.error && (
                                <div className="mb-3 rounded-md bg-danger-50 border border-danger-200 text-danger-800 px-3 py-2 text-sm">{flash.error}</div>
                            )}
                            <div className="space-y-3 max-h-64 overflow-y-auto">
                                {items.map((item, i) => (
                                    <div key={item.key || i} className={`flex items-start justify-between text-sm gap-2 ${removing === item.key ? 'opacity-50' : ''}`}>
                                        <span className="min-w-0 text-ink-700/80">
                                            {item.name} <span className="text-ink-700/70">× {item.qty}</span>
                                            {item.key && (
                                                <button type="button" onClick={() => removeItem(item.key)} disabled={!!removing}
                                                        aria-label={`Remove ${item.name}`}
                                                        className="ml-2 inline-flex items-center gap-1 text-xs text-danger-600 hover:underline disabled:opacity-50 align-baseline">
                                                    <Icon name="close" className="w-3 h-3 shrink-0" />{removing === item.key ? 'Removing…' : 'Remove'}
                                                </button>
                                            )}
                                            {item.promo && (
                                                <span className="block text-[11px] text-success-700">
                                                    {item.promo.label} · you save {item.promo.saving_text}
                                                    {item.promo.ends && <OfferCountdown ends={item.promo.ends} className="ml-1 font-semibold text-danger-600" />}
                                                </span>
                                            )}
                                        </span>
                                        <span className="font-medium shrink-0">{item.lineText}</span>
                                    </div>
                                ))}
                            </div>

                            {/* No coupon box here (owner, 2026-09-23) — a code is
                                entered on the cart page. One already applied
                                still says so, so the discount line below is
                                accounted for and can be undone. */}
                            {coupon && (
                                <div className="mt-4 pt-4 border-t border-ink-100">
                                    <div className="flex items-center justify-between text-sm rounded-md bg-success-50 border border-success-200 px-3 py-2">
                                        <span className="text-success-800 inline-flex items-center gap-1.5"><Icon name="tag" className="w-4 h-4 shrink-0" /><span className="min-w-0">Coupon <strong className="font-mono">{coupon.code}</strong> applied</span></span>
                                        <button type="button" onClick={removeCoupon} disabled={couponBusy} className="text-xs text-danger-600 hover:underline disabled:opacity-50">Remove</button>
                                    </div>
                                </div>
                            )}

                            <dl className="space-y-2 text-sm border-t border-ink-100 mt-4 pt-4">
                                <div className="flex justify-between"><dt className="text-ink-700/70">Subtotal</dt><dd>{view.subtotalText}</dd></div>
                                {view.discountLines.length ? view.discountLines.map((line, i) => (
                                    <div key={i} className="flex justify-between text-success-700"><dt>{line.label}</dt><dd>−{line.amount_text}</dd></div>
                                )) : (view.discountText && (
                                    <div className="flex justify-between text-success-700"><dt>Discount</dt><dd>−{view.discountText}</dd></div>
                                ))}
                                <div className="flex justify-between">
                                    <dt className="text-ink-700/70">Shipping</dt>
                                    <dd>{deliverySaved > 0
                                        ? <><s className="mr-1 text-ink-700/50"><span className="sr-only">was </span>{money(deliverySaved)}</s><span className="text-success-700">Free</span></>
                                        : money(ship)}</dd>
                                </div>
                                <div className="flex justify-between font-semibold text-base border-t border-ink-100 pt-3"><dt>Total</dt><dd>{wasPrice}{money(total)}</dd></div>
                            </dl>

                            {/* The whole saving, delivery included — the same figure
                                as the line under Place order. The percentage is
                                off what the order would have cost. */}
                            {saving && (
                                <div className="mt-3 rounded-md bg-success-50 border border-success-200 text-success-800 px-3 py-2 text-sm font-medium">
                                    You're saving {money(saved)}{original > 0 && Math.round(saved / original * 100) > 0 ? ` (${Math.round(saved / original * 100)}% off)` : ''}
                                </div>
                            )}

                            {view.hints.map((hint, i) => (
                                <div key={i} className="mt-3 rounded-md bg-warning-50 border border-warning-200 text-warning-800 px-3 py-2 text-xs flex items-center gap-1.5"><Icon name="gift" className="w-3.5 h-3.5 shrink-0" />{hint}</div>
                            ))}

                            {view.coupon_notice && (
                                <div className="mt-3 rounded-md bg-gold-50 border border-gold-200 text-ink-800 px-3 py-2 text-xs flex items-start gap-1.5">
                                    <Icon name="bulb" className="w-3.5 h-3.5 shrink-0 mt-[1px] text-gold-700" />
                                    <span>{view.coupon_notice}</span>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {loyalty && <Points loyalty={loyalty} />}

                {registerPct && (
                    /* The pill and ONE paragraph holding the whole sentence are
                       the only flex items. Bare text either side of an element
                       child becomes its own anonymous flex item, and with
                       `items-center` each one is then a rigid column that wraps
                       independently — which is why this row used to read "Get
                       an" / "2%" / "off — plus…" stacked. `min-w-0` lets that
                       span shrink below its min-content width so the text
                       reflows normally. Same shape as Product.jsx:336. */
                    <div className="rounded-md bg-ink-900 text-white px-3 py-2.5 text-xs">
                        <div className="flex items-start gap-2">
                            <MemberPill />
                            <p className="min-w-0 leading-relaxed">
                                {registerPct.saving >= 50 ? (
                                    <>Save <strong className="font-semibold">{registerPct.savingText}</strong> on this order</>
                                ) : (
                                    <>Get <strong className="font-semibold">{registerPct.pct}%</strong> off this order</>
                                )}
                                <span className="text-white/65"> — member price, plus loyalty points on every order.</span>
                            </p>
                        </div>
                        <a href={chromeUrls.register} className="mt-2 inline-flex items-center gap-1 rounded bg-white/10 px-2.5 py-1 font-medium hover:bg-white/20">
                            Create account <span aria-hidden="true">→</span>
                        </a>
                    </div>
                )}
            </form>
        </div>
    );
}

function Points({ loyalty }) {
    const [busy, setBusy] = useState(false);
    const [pts, setPts] = useState(loyalty.defaultRedeem);

    const apply = async (remove) => {
        setBusy(true);
        try {
            if (remove) {
                await fetchJson(loyalty.pointsUrl, { method: 'DELETE' });
            } else {
                await fetchJson(loyalty.pointsUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ points: pts }),
                });
            }
            router.reload({ onFinish: () => setBusy(false) });
        } catch (e) {
            setBusy(false);
        }
    };

    return (
        <div className="rounded-md border border-gold-200 bg-gold-50 p-3 text-sm">
            {loyalty.applied > 0 ? (
                <div className="flex items-center justify-between">
                    <span className="inline-flex items-center gap-1.5"><Icon name="check" className="w-4 h-4 shrink-0" /><strong>{loyalty.applied}</strong> points redeemed (−{loyalty.appliedDiscountText})</span>
                    <button type="button" onClick={() => apply(true)} disabled={busy} className="text-xs text-danger-600 hover:underline">Remove</button>
                </div>
            ) : (
                <>
                    <p className="mb-2">You have <strong>{loyalty.points}</strong> points (worth {loyalty.pointsValueText}). Redeem in steps of {loyalty.step}.</p>
                    <div className="flex items-center gap-2">
                        <input
                            type="number"
                            min={loyalty.minRedeem}
                            step={loyalty.step}
                            max={loyalty.points}
                            value={pts}
                            onChange={(e) => setPts(e.target.value)}
                            aria-label="Points to redeem"
                            className="input py-1.5 w-28 text-sm"
                        />
                        <button type="button" onClick={() => apply(false)} disabled={busy} className="btn-outline text-xs py-1.5 px-3">Apply points</button>
                    </div>
                </>
            )}
        </div>
    );
}

Checkout.layout = (page) => <Layout minimalFooter>{page}</Layout>;
