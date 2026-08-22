import { useEffect, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import { csrf, fetchJson, money } from '../Shared/format';

// COD checkout — the money page. Faithful port of shop/checkout.blade.php:
// live shipping-zone totals, abandoned-cart lead capture on phone blur,
// loyalty point redemption, and the InitiateCheckout pixel event.
export default function Checkout({ items, summary, prefill, isMember, loyalty, registerPct, trustBadges, ic, urls, coupon }) {
    const { props } = usePage();
    const chromeUrls = props.chrome?.urls || {};
    const errors = props.errors || {};
    const errorList = Object.values(errors).flat();
    const leadSent = useRef(false);
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
    const ship = (summary.freeThreshold !== null && summary.rawSubtotal >= summary.freeThreshold)
        ? 0
        : (inside ? summary.shipInside : summary.shipOutside);
    const total = summary.sub + ship;

    // Capture the lead the moment a valid phone is typed — a COD order the
    // customer abandons is still a phone number the team can follow up.
    const captureLead = () => {
        if (leadSent.current) return;
        if (!/^(\+?880|0)1[3-9]\d{8}$/.test((form.data.phone || '').trim())) return;
        leadSent.current = true;
        fetch(urls.lead, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ phone: form.data.phone, name: form.data.name, email: null }),
        }).catch(() => { leadSent.current = false; });
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(urls.store);
    };

    return (
        <div className="mx-auto max-w-6xl px-4 py-8">
            <h1 className="font-display text-3xl font-semibold mb-6">Checkout</h1>

            {errorList.length > 0 && (
                <div className="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm mb-6">
                    <ul className="list-disc list-inside">{errorList.map((e, i) => <li key={i}>{e}</li>)}</ul>
                </div>
            )}

            <form onSubmit={submit} className="grid lg:grid-cols-3 gap-8">
                <div className="lg:col-span-2 card p-6 space-y-4">
                    <h2 className="font-display text-xl font-semibold">Delivery details</h2>
                    {!isMember && (
                        <p className="text-sm text-ink-700/60">Have an account? <a href={chromeUrls.login} className="text-gold-700 hover:underline">Log in</a> for faster checkout.</p>
                    )}

                    <div className="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label className="label">Full name *</label>
                            <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="input" required autoComplete="name" />
                        </div>
                        <div>
                            {/* The phone gets the numeric keypad — this form is filled
                                one-thumbed on a phone, and a COD order lives or dies
                                on this field. */}
                            <label className="label">Mobile number *</label>
                            <input
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
                        </div>
                    </div>
                    <div>
                        <label className="label">Full address *</label>
                        <textarea value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} rows={2} className="input" required autoComplete="street-address" />
                    </div>
                    <div>
                        <label className="label">Area / Thana</label>
                        <input value={form.data.area} onChange={(e) => form.setData('area', e.target.value)} className="input" autoComplete="address-level2" />
                    </div>

                    <div>
                        <span className="label">Delivery zone</span>
                        <div className="flex gap-3">
                            <label className={`flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm ${inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'}`}>
                                <input type="radio" name="is_inside_dhaka" value="1" checked={inside} onChange={() => form.setData('is_inside_dhaka', '1')} className="sr-only" />
                                Inside Dhaka — ৳{summary.shipInside}
                            </label>
                            <label className={`flex-1 cursor-pointer rounded-md border px-4 py-3 text-sm ${!inside ? 'border-gold-500 bg-gold-100' : 'border-ink-100'}`}>
                                <input type="radio" name="is_inside_dhaka" value="0" checked={!inside} onChange={() => form.setData('is_inside_dhaka', '0')} className="sr-only" />
                                Outside Dhaka — ৳{summary.shipOutside}
                            </label>
                        </div>
                    </div>

                    {/* Gift option: the message is stored on the order (card_message)
                        and printed on the thank-you card the team already packs. */}
                    <div className={`rounded-xl border p-4 transition-colors ${form.data.is_gift ? 'border-gold-400 bg-gold-50/60' : 'border-ink-100'}`}>
                        <label className="flex items-start gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={form.data.is_gift}
                                onChange={(e) => form.setData('is_gift', e.target.checked)}
                                className="mt-1 h-4 w-4 accent-gold-600"
                            />
                            <span>
                                <span className="block text-sm font-semibold">🎁 This is a gift</span>
                                <span className="block text-xs text-ink-700/60 mt-0.5">We pack it gift-ready with no price slip in the box, and you can add a personal message on a card.</span>
                            </span>
                        </label>
                        {form.data.is_gift && (
                            <div className="mt-3">
                                <label className="label text-xs">Message for the card (optional)</label>
                                <textarea
                                    value={form.data.card_message}
                                    onChange={(e) => form.setData('card_message', e.target.value.slice(0, 240))}
                                    rows={3}
                                    maxLength={240}
                                    placeholder="Happy anniversary, Nadia — every year with you shines brighter. Love, Rafi"
                                    className="input"
                                />
                                <div className="flex items-center justify-between mt-1 text-[11px] text-ink-700/50">
                                    <span>Hand-written on our card and tucked inside the box.</span>
                                    <span>{form.data.card_message.length}/240</span>
                                </div>
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="label">Order note (optional)</label>
                        <textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} className="input" />
                    </div>
                </div>

                <div className="card p-6 h-fit">
                    <h2 className="font-display text-xl font-semibold mb-4">Your order</h2>

                    {props.flash?.success && (
                        <div className="mb-3 rounded-md bg-green-50 border border-green-200 text-green-800 px-3 py-2 text-sm">{props.flash.success}</div>
                    )}
                    {props.flash?.error && (
                        <div className="mb-3 rounded-md bg-red-50 border border-red-200 text-red-800 px-3 py-2 text-sm">{props.flash.error}</div>
                    )}
                    <div className="space-y-3 max-h-64 overflow-y-auto">
                        {items.map((item, i) => (
                            <div key={i} className="flex justify-between text-sm gap-2">
                                <span className="text-ink-700/80">{item.name} <span className="text-ink-700/50">× {item.qty}</span></span>
                                <span className="font-medium shrink-0">{item.lineText}</span>
                            </div>
                        ))}
                    </div>
                    {/* Coupon — same endpoints as the cart page, so a code entered
                        here or there behaves identically. */}
                    <div className="mt-4 pt-4 border-t border-ink-100">
                        {coupon ? (
                            <div className="flex items-center justify-between text-sm rounded-md bg-green-50 border border-green-200 px-3 py-2">
                                <span className="text-green-800">🏷️ Coupon <strong className="font-mono">{coupon.code}</strong> applied</span>
                                <button type="button" onClick={removeCoupon} disabled={couponBusy} className="text-xs text-red-600 hover:underline disabled:opacity-50">Remove</button>
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
                            <div key={i} className="flex justify-between text-green-700"><dt>{line.label}</dt><dd>−{line.amount_text}</dd></div>
                        )) : (summary.discountText && (
                            <div className="flex justify-between text-green-700"><dt>Discount</dt><dd>−{summary.discountText}</dd></div>
                        ))}
                        <div className="flex justify-between"><dt className="text-ink-700/70">Shipping</dt><dd>৳{ship}</dd></div>
                        <div className="flex justify-between font-semibold text-base border-t border-ink-100 pt-3"><dt>Total</dt><dd>{money(total)}</dd></div>
                    </dl>

                    {summary.discountText && (
                        <div className="mt-3 rounded-md bg-green-50 border border-green-200 text-green-800 px-3 py-2 text-sm font-medium">
                            🎉 You're saving {summary.discountText}{summary.discountPct > 0 ? ` (${summary.discountPct}% off)` : ''}
                        </div>
                    )}

                    {summary.hints.map((hint, i) => (
                        <div key={i} className="mt-3 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 text-xs">🎁 {hint}</div>
                    ))}

                    {loyalty && <Points loyalty={loyalty} />}

                    {registerPct && (
                        <div className="mt-3 rounded-md bg-ink-900 text-white px-3 py-2.5 text-xs flex items-center justify-between gap-2">
                            <span>🎉 Get an extra <strong>{registerPct}%</strong> off — plus loyalty points on every order.</span>
                            <a href={chromeUrls.register} className="shrink-0 underline font-medium">Create account</a>
                        </div>
                    )}

                    <div className="mt-4 rounded-md bg-gold-100/60 p-3 text-sm">💵 <strong>Cash on Delivery</strong> — pay when you receive your order.</div>
                    <button type="submit" className="btn-primary w-full mt-6" disabled={form.processing}>
                        {form.processing ? 'Placing order…' : 'Place order'}
                    </button>

                    {trustBadges.length > 0 && (
                        <div className="mt-4 grid gap-2 text-center text-xs text-ink-700/70" style={{ gridTemplateColumns: `repeat(${Math.min(4, Math.max(1, trustBadges.length))}, minmax(0,1fr))` }}>
                            {trustBadges.map((b, i) => (
                                <div key={i} className="rounded-lg bg-gold-100/60 p-3">
                                    <span className="text-base">{b.icon || '✓'}</span><br />
                                    <span className="font-medium">{b.title}</span>
                                    {b.text && <><br /><span className="text-[10px] text-ink-700/50">{b.text}</span></>}
                                </div>
                            ))}
                        </div>
                    )}
                    <p className="mt-3 text-center text-xs text-ink-700/50">No advance payment needed · We call to confirm every order</p>
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
            router.reload();
        } catch (e) {
            setBusy(false);
        }
    };

    return (
        <div className="mt-3 rounded-md border border-gold-200 bg-gold-50 p-3 text-sm">
            {loyalty.applied > 0 ? (
                <div className="flex items-center justify-between">
                    <span>✓ <strong>{loyalty.applied}</strong> points redeemed (−{loyalty.appliedDiscountText})</span>
                    <button type="button" onClick={() => apply(true)} disabled={busy} className="text-xs text-red-600 hover:underline">Remove</button>
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
