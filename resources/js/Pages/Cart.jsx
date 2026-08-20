import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import SmartLink from '../Shared/SmartLink';

// Full cart page. Mutations go through Inertia (server redirects back to /cart
// with fresh props + flash), with optimistic qty display while in flight.
export default function Cart({ items, summary, coupon, freeBar, offersPanel, memberUsage, suggestions, cartUrls }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const [code, setCode] = useState('');
    const [busyKey, setBusyKey] = useState(null);

    const updateQty = (key, qty) => {
        setBusyKey(key);
        router.patch(cartUrls.update, { key, qty }, {
            preserveScroll: true,
            onFinish: () => setBusyKey(null),
        });
    };

    const removeItem = (key) => {
        setBusyKey(key);
        router.delete(cartUrls.remove, {
            data: { key },
            preserveScroll: true,
            onFinish: () => setBusyKey(null),
        });
    };

    const applyCoupon = (e) => {
        e.preventDefault();
        if (!code.trim()) return;
        router.post(cartUrls.couponApply, { code }, { preserveScroll: true, onSuccess: () => setCode('') });
    };

    if (!items.length) {
        return (
            <div className="mx-auto max-w-5xl px-4 py-8">
                <h1 className="font-display text-3xl font-semibold mb-6">Your cart</h1>
                <div className="card p-12 text-center">
                    <p className="text-ink-700/60">Your cart is empty.</p>
                    <Link href={urls.shop || '/shop'} className="btn-primary mt-4">Start shopping</Link>
                </div>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-5xl px-4 py-8">
            <h1 className="font-display text-3xl font-semibold mb-6">Your cart</h1>

            {freeBar && (
                <div className="card p-4 mb-6">
                    {freeBar.unlocked ? (
                        <p className="text-sm text-center mb-2 text-green-700 font-medium">🎉 You've unlocked free delivery!</p>
                    ) : (
                        <p className="text-sm text-center mb-2">Add <strong>{freeBar.remaining_text}</strong> more to unlock <strong>free delivery!</strong></p>
                    )}
                    <div className="h-2 rounded-full bg-gold-100 overflow-hidden">
                        <div className="h-full bg-accent transition-all" style={{ width: `${freeBar.pct}%` }} />
                    </div>
                </div>
            )}

            {offersPanel && (
                <div className="card p-4 mb-6 space-y-2">
                    {offersPanel.matched.map((o, i) => (
                        <p key={i} className="text-sm text-green-700 flex items-center gap-2">✓ <strong>{o.title}</strong> applied</p>
                    ))}
                    {offersPanel.nearly && (
                        <>
                            <p className="text-sm">Add <strong>{offersPanel.nearly.remaining_text}</strong> more to unlock <strong>{offersPanel.nearly.title}</strong></p>
                            <div className="h-2 rounded-full bg-gold-100 overflow-hidden">
                                <div className="h-full bg-accent transition-all" style={{ width: `${offersPanel.nearly.pct}%` }} />
                            </div>
                        </>
                    )}
                    {offersPanel.register_hint && (
                        <p className="text-sm text-ink-700/70">💡 <a href={urls.register} className="text-gold-700 underline">Register</a> to unlock members-only savings.</p>
                    )}
                </div>
            )}

            <div className="grid lg:grid-cols-3 gap-8">
                <div className="lg:col-span-2 space-y-4">
                    {items.map((item) => (
                        <div key={item.key} className={`card p-4 flex gap-4 items-center transition-opacity ${busyKey === item.key ? 'opacity-60' : ''}`}>
                            <div className="w-20 h-20 rounded-lg bg-gold-100 overflow-hidden shrink-0">
                                {item.image && <img src={item.image} className="w-full h-full object-cover" alt="" />}
                            </div>
                            <div className="flex-1 min-w-0">
                                <Link href={item.url} className="font-medium hover:text-gold-700">{item.name}</Link>
                                {item.attributes && <p className="text-xs text-ink-700/60">{item.attributes}</p>}
                                <p className="text-sm text-gold-700 font-semibold mt-1">{item.price_text}</p>
                                {item.offer && (
                                    <p className="mt-1 inline-block badge bg-green-100 text-green-700 text-[11px]">
                                        {item.offer.label} (you save {item.offer.saving_text})
                                    </p>
                                )}
                            </div>
                            <div className="inline-flex items-center rounded-md border border-ink-100 bg-white">
                                <button type="button" onClick={() => updateQty(item.key, item.qty - 1)} className="px-2.5 py-1.5 hover:text-gold-700" aria-label="Decrease quantity">−</button>
                                <span className="w-8 text-center text-sm">{item.qty}</span>
                                <button type="button" onClick={() => updateQty(item.key, Math.min(99, item.qty + 1))} className="px-2.5 py-1.5 hover:text-gold-700" aria-label="Increase quantity">+</button>
                            </div>
                            <div className="text-right w-24">
                                <p className="font-semibold">{item.line_total_text}</p>
                                <button type="button" onClick={() => removeItem(item.key)} className="text-xs text-red-600 hover:underline mt-1">Remove</button>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="card p-6 h-fit">
                    <h2 className="font-display text-xl font-semibold mb-4">Summary</h2>

                    {coupon ? (
                        <div className="flex justify-between text-sm mb-2">
                            <span className="text-green-700">Coupon {coupon.code}</span>
                            <button type="button" onClick={() => router.delete(cartUrls.couponRemove, { preserveScroll: true })} className="text-xs text-red-600 hover:underline">remove</button>
                        </div>
                    ) : (
                        <form onSubmit={applyCoupon} className="flex gap-2 mb-4">
                            <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Coupon code" className="input py-2" />
                            <button className="btn-outline">Apply</button>
                        </form>
                    )}

                    <dl className="space-y-2 text-sm border-t border-ink-100 pt-4">
                        <div className="flex justify-between"><dt className="text-ink-700/70">Subtotal</dt><dd>{summary.subtotal_text}</dd></div>
                        {summary.discount_lines.map((line, i) => (
                            <div key={i} className="flex justify-between text-green-700"><dt>{line.label}</dt><dd>−{line.amount_text}</dd></div>
                        ))}
                        {summary.free_shipping_offer && (
                            <div className="flex justify-between text-green-700"><dt>Free delivery offer</dt><dd>unlocked 🎉</dd></div>
                        )}
                        <div className="flex justify-between"><dt className="text-ink-700/70">Shipping</dt><dd className="text-ink-700/60">calculated at checkout</dd></div>
                        <div className="flex justify-between font-semibold text-base border-t border-ink-100 pt-3">
                            <dt>Estimated total</dt><dd>{summary.total_text}</dd>
                        </div>
                    </dl>

                    {memberUsage && (
                        <p className={`mt-2 text-xs ${memberUsage.remaining > 0 ? 'text-green-700' : 'text-ink-700/60'}`}>
                            {memberUsage.remaining > 0
                                ? <>💎 Member discount: <strong>{memberUsage.remaining} of {memberUsage.max}</strong> uses left{memberUsage.resets ? ` (resets ${memberUsage.resets})` : ''}.</>
                                : <>💎 Member discount used up for now{memberUsage.resets ? ` — resets ${memberUsage.resets}` : ''}.</>}
                        </p>
                    )}

                    {summary.hints.map((hint, i) => (
                        <div key={i} className="mt-3 rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 text-xs">🎁 {hint}</div>
                    ))}

                    <SmartLink href={urls.checkout || '/checkout'} className="btn-primary w-full mt-6">Proceed to checkout</SmartLink>
                    <Link href={urls.shop || '/shop'} className="block text-center text-sm text-gold-700 hover:underline mt-3">Continue shopping</Link>
                </div>
            </div>

            {suggestions.length > 0 && (
                <section className="mt-14">
                    <h2 className="font-display text-2xl font-semibold mb-6">You may also like</h2>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-8">
                        {suggestions.map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
                </section>
            )}
        </div>
    );
}

Cart.layout = (page) => <Layout>{page}</Layout>;
