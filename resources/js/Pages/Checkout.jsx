import { useEffect, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import { fetchJson, money } from '../Shared/format';
import Icon, { IconOrGlyph } from '../Shared/Icons';

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
export default function Checkout({ items, summary, prefill, isMember, loyalty, registerPct, trustBadges, ic, urls, coupon, freeShipping, gift }) {
    const { props } = usePage();
    const chromeUrls = props.chrome?.urls || {};
    const errors = props.errors || {};
    // Per-field messages, the way Account/Profile.jsx:22 already does it — the
    // middleware shares MessageBag::getMessages(), so each value is an array.
    // The old code flattened these into one banner, throwing away which field
    // each message belonged to.
    const err = (k) => errors[k]?.[0];
    // DOM order of the form's fields — decides which one gets focus on failure.
    const FIELD_ORDER = ['name', 'phone', 'address', 'area', 'is_inside_dhaka', 'is_gift', 'card_message', 'notes'];
    // `email` and `district` are validated server-side but have no input here;
    // without this they would fail silently.
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
    const [code, setCode] = useState('');
    const [couponBusy, setCouponBusy] = useState(false);

    // Coupon apply/remove go through Inertia: the server answers with
    // back() → this page re-renders with the new totals + a flash message.
    const applyCoupon = (e) => {
        e.preventDefault();
        if (!code.trim() || couponBusy) return;
        setCouponBusy(true);
        router.post(urls.couponApply, { code }, {
            preserveScroll: true,
            onSuccess: () => setCode(''),
            onFinish: () => setCouponBusy(false),
        });
    };
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

    // Most guests never touch the zone picker, and the default (outside Dhaka)
    // is the dearer one. If the typed address says Dhaka, pre-select inside —
    // the customer can still override, and the server charges by the picker.
    const zoneTouched = useRef(false);
    useEffect(() => {
        if (zoneTouched.current) return;
        const text = `${form.data.address} ${form.data.area}`.toLowerCase();
        if (/\bdhaka\b/.test(text) && form.data.is_inside_dhaka !== '1') form.setData('is_inside_dhaka', '1');
    }, [form.data.address, form.data.area]);
    // The server is the authority on free delivery (threshold, coupon, offer
    // or a per-customer perk) — mirror its verdict rather than re-deriving it.
    const ship = freeShipping ? 0 : (inside ? summary.shipInside : summary.shipOutside);
    const total = summary.sub + ship;

    // Capture the lead the moment a valid phone is typed — a COD order the
    // customer abandons is still a phone number the team can follow up.
    const captureLead = () => {
        const phone = bdPhone(form.data.phone);
        // App\Rules\BdPhone's pattern, applied after canonicalisation.
        if (!/^01[3-9]\d{8}$/.test(phone)) return;
        const name = (form.data.name || '').trim();
        const key = `${phone}|${name}`;
        if (key === leadSent.current || leadBusy.current) return;
        leadBusy.current = true;
        // fetchJson throws on any non-2xx, so a 429 from the endpoint's own
        // throttle leaves leadSent untouched and the next blur retries. The old
        // bare fetch() set the latch before the request and never cleared it on
        // an HTTP-level failure.
        fetchJson(urls.lead, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, name: name || null, email: null }),
        })
            .then(() => { leadSent.current = key; })
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
        <div className="mx-auto max-w-6xl px-4 py-8">
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

            <form onSubmit={submit} className="grid lg:grid-cols-3 gap-8">
                <div className="lg:col-span-2 card p-6 space-y-4">
                    <h2 className="font-display text-xl font-semibold">Delivery details</h2>
                    {!isMember && (
                        <p className="text-sm text-ink-700/70">Have an account? <a href={chromeUrls.login} className="text-gold-700 hover:underline">Log in</a> for faster checkout.</p>
                    )}

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
                                {...a11y('phone', 'phone-help')}
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
                            <p id="phone-help" className="mt-1 text-[11px] text-ink-700/70">
                                We save this so we can call you about the order — including if you
                                do not finish checking out.
                            </p>
                            {err('phone') && <p id="co-phone-error" className="text-xs text-danger-600 mt-1">{err('phone')}</p>}
                        </div>
                    </div>
                    <div data-field="address">
                        <label className="label" htmlFor="co-address">Full address *</label>
                        <textarea {...a11y('address')} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} rows={2} className="input" required autoComplete="street-address" />
                        {err('address') && <p id="co-address-error" className="text-xs text-danger-600 mt-1">{err('address')}</p>}
                    </div>
                    <div data-field="area">
                        <label className="label" htmlFor="co-area">Area / Thana</label>
                        <input {...a11y('area')} value={form.data.area} onChange={(e) => form.setData('area', e.target.value)} className="input" autoComplete="address-level2" />
                        {err('area') && <p id="co-area-error" className="text-xs text-danger-600 mt-1">{err('area')}</p>}
                    </div>

                    {freeShipping ? (
                        /* Nothing to choose between when both zones cost ৳0 —
                           the zone still travels with the order, inferred from
                           the address above. */
                        <div className="rounded-xl border border-success-200 bg-success-50 px-4 py-3.5 flex items-center gap-3">
                            <Icon name="sparkle" className="w-6 h-6 text-gold-700 shrink-0" />
                            <div>
                                <p className="text-sm font-semibold text-success-800">Free delivery unlocked</p>
                                <p className="text-xs text-success-700/80 mt-0.5">
                                    Anywhere in Bangladesh — we cover the courier on this order.
                                </p>
                            </div>
                        </div>
                    ) : (
                    <div data-field="is_inside_dhaka">
                        <span className="label">Delivery zone</span>
                        <div className="flex gap-3" role="radiogroup" aria-label="Delivery zone">
                            <label className={`flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm ${inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'}`}>
                                <input id="co-is_inside_dhaka" aria-invalid={err('is_inside_dhaka') ? 'true' : undefined} type="radio" name="is_inside_dhaka" value="1" checked={inside} onChange={() => { zoneTouched.current = true; form.setData('is_inside_dhaka', '1'); }} className="sr-only" />
                                Inside Dhaka — ৳{summary.shipInside}
                            </label>
                            <label className={`flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm ${!inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'}`}>
                                <input type="radio" name="is_inside_dhaka" value="0" checked={!inside} onChange={() => { zoneTouched.current = true; form.setData('is_inside_dhaka', '0'); }} className="sr-only" />
                                Outside Dhaka — ৳{summary.shipOutside}
                            </label>
                        </div>
                        {/* The radios are sr-only, so the effect scrolls to the
                            [data-field] wrapper and focuses the input separately. */}
                        {err('is_inside_dhaka') && <p id="co-is_inside_dhaka-error" className="text-xs text-danger-600 mt-1">{err('is_inside_dhaka')}</p>}
                    </div>
                    )}

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

                <div className="card p-6 h-fit">
                    <h2 className="font-display text-xl font-semibold mb-4">Your order</h2>

                    {props.flash?.success && (
                        <div className="mb-3 rounded-md bg-success-50 border border-success-200 text-success-800 px-3 py-2 text-sm">{props.flash.success}</div>
                    )}
                    {props.flash?.error && (
                        <div className="mb-3 rounded-md bg-danger-50 border border-danger-200 text-danger-800 px-3 py-2 text-sm">{props.flash.error}</div>
                    )}
                    <div className="space-y-3 max-h-64 overflow-y-auto">
                        {items.map((item, i) => (
                            <div key={i} className="flex justify-between text-sm gap-2">
                                <span className="text-ink-700/80">{item.name} <span className="text-ink-700/70">× {item.qty}</span></span>
                                <span className="font-medium shrink-0">{item.lineText}</span>
                            </div>
                        ))}
                    </div>
                    {/* Coupon — same endpoints as the cart page, so a code entered
                        here or there behaves identically. */}
                    <div className="mt-4 pt-4 border-t border-ink-100">
                        {coupon ? (
                            <div className="flex items-center justify-between text-sm rounded-md bg-success-50 border border-success-200 px-3 py-2">
                                <span className="text-success-800 inline-flex items-center gap-1.5"><Icon name="tag" className="w-4 h-4 shrink-0" /><span className="min-w-0">Coupon <strong className="font-mono">{coupon.code}</strong> applied</span></span>
                                <button type="button" onClick={removeCoupon} disabled={couponBusy} className="text-xs text-danger-600 hover:underline disabled:opacity-50">Remove</button>
                            </div>
                        ) : (
                            <div className="flex gap-2">
                                <input
                                    value={code}
                                    onChange={(e) => setCode(e.target.value.toUpperCase())}
                                    onKeyDown={(e) => { if (e.key === 'Enter') applyCoupon(e); }}
                                    placeholder="Coupon code"
                                    autoComplete="off"
                                    className="input py-2 font-mono uppercase"
                                    aria-label="Coupon code"
                                />
                                <button type="button" onClick={applyCoupon} disabled={!code.trim() || couponBusy} className="btn-outline whitespace-nowrap disabled:opacity-50">
                                    {couponBusy ? '…' : 'Apply'}
                                </button>
                            </div>
                        )}
                    </div>

                    <dl className="space-y-2 text-sm border-t border-ink-100 mt-4 pt-4">
                        <div className="flex justify-between"><dt className="text-ink-700/70">Subtotal</dt><dd>{summary.subtotalText}</dd></div>
                        {summary.discountLines.length ? summary.discountLines.map((line, i) => (
                            <div key={i} className="flex justify-between text-success-700"><dt>{line.label}</dt><dd>−{line.amount_text}</dd></div>
                        )) : (summary.discountText && (
                            <div className="flex justify-between text-success-700"><dt>Discount</dt><dd>−{summary.discountText}</dd></div>
                        ))}
                        <div className="flex justify-between"><dt className="text-ink-700/70">Shipping</dt><dd>৳{ship}</dd></div>
                        <div className="flex justify-between font-semibold text-base border-t border-ink-100 pt-3"><dt>Total</dt><dd>{money(total)}</dd></div>
                    </dl>

                    {summary.discountText && (
                        <div className="mt-3 rounded-md bg-success-50 border border-success-200 text-success-800 px-3 py-2 text-sm font-medium">
                            You're saving {summary.discountText}{summary.discountPct > 0 ? ` (${summary.discountPct}% off)` : ''}
                        </div>
                    )}

                    {summary.hints.map((hint, i) => (
                        <div key={i} className="mt-3 rounded-md bg-warning-50 border border-warning-200 text-warning-800 px-3 py-2 text-xs flex items-center gap-1.5"><Icon name="gift" className="w-3.5 h-3.5 shrink-0" />{hint}</div>
                    ))}

                    {loyalty && <Points loyalty={loyalty} />}

                    {registerPct && (
                        /* The icon and the CTA are the only flex items besides ONE
                           span holding the whole sentence. Bare text either side of
                           an element child becomes its own anonymous flex item, and
                           with `items-center` each one is then a rigid column that
                           wraps independently — which is why this row used to read
                           "Get an" / "2%" / "off — plus…" stacked. `min-w-0` lets
                           that span shrink below its min-content width so the text
                           reflows normally. Same shape as Product.jsx:336. */
                        <div className="mt-3 rounded-md bg-ink-900 text-white px-3 py-2.5 text-xs">
                            {/* Exactly two flex items — the icon and ONE paragraph
                                that holds the entire sentence. Nothing here can
                                fragment. The CTA is a normal block below rather
                                than a third flex item, because this card is only
                                ~210px wide on a phone and no arrangement fits the
                                sentence and a button on one line at that size. */}
                            <div className="flex items-start gap-2">
                                <Icon name="sparkle" className="w-4 h-4 shrink-0 mt-px text-gold-300" />
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

                    <div className="mt-4 rounded-md bg-gold-100/60 p-3 text-sm flex items-center gap-2"><Icon name="cash" className="w-5 h-5 shrink-0" /><span><strong>Cash on Delivery</strong> — pay when you receive your order.</span></div>
                    <button type="submit" className="btn-primary w-full mt-6" disabled={form.processing}>
                        {form.processing ? 'Placing order…' : 'Place order'}
                    </button>

                    {trustBadges.length > 0 && (
                        <div className="mt-4 grid gap-2 text-center text-xs text-ink-700/70" style={{ gridTemplateColumns: `repeat(${Math.min(4, Math.max(1, trustBadges.length))}, minmax(0,1fr))` }}>
                            {trustBadges.map((b, i) => (
                                <div key={i} className="rounded-lg bg-gold-100/60 p-3">
                                    <span className="mx-auto mb-1 flex w-fit text-gold-700"><IconOrGlyph value={b.icon} fallback="shieldCheck" className="w-5 h-5" /></span>
                                    <span className="font-medium">{b.title}</span>
                                    {b.text && <><br /><span className="text-[10px] text-ink-700/70">{b.text}</span></>}
                                </div>
                            ))}
                        </div>
                    )}
                    <p className="mt-3 text-center text-xs text-ink-700/70">No advance payment needed · We call to confirm every order</p>
                </div>
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
        <div className="mt-3 rounded-md border border-gold-200 bg-gold-50 p-3 text-sm">
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

Checkout.layout = (page) => <Layout>{page}</Layout>;
