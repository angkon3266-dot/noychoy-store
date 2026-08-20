import { usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { useCart } from '../CartContext';
import SmartLink from '../SmartLink';

// Mini-cart slide-over — same /cart/mini data contract as the Alpine drawer.
export default function MiniCart() {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const { drawer, setDrawer, count, items, subtotalText, discountLines, hints, freeShipping, remove } = useCart();

    return (
        <div className={`fixed inset-0 z-[60] ${drawer ? '' : 'pointer-events-none'}`} aria-hidden={!drawer}>
            <div
                className={`absolute inset-0 bg-black/25 transition-opacity duration-300 ${drawer ? 'opacity-100' : 'opacity-0'}`}
                onClick={() => setDrawer(false)}
            />
            <div
                className={`absolute right-0 top-0 h-full w-[82%] max-w-[340px] bg-white shadow-2xl flex flex-col transition-transform duration-300 ease-out ${drawer ? 'translate-x-0' : 'translate-x-full'}`}
            >
                <div className="flex items-center justify-between px-5 py-4 border-b border-ink-100">
                    <h2 className="font-display text-lg font-semibold">Your cart ({count})</h2>
                    <button onClick={() => setDrawer(false)} className="p-1 text-ink-700/60 hover:text-ink-900 text-2xl leading-none">×</button>
                </div>
                <div className="flex-1 overflow-y-auto p-5 space-y-4">
                    {!items.length && <p className="text-center text-ink-700/50 py-10">Your cart is empty.</p>}
                    {items.map((item) => (
                        <div key={item.key} className="relative flex gap-3 group/ci">
                            <SmartLink href={item.url} onClick={() => setDrawer(false)} className="flex gap-3 flex-1 min-w-0 pr-6">
                                <span className="w-16 h-16 rounded-lg bg-gold-100 overflow-hidden shrink-0">
                                    {item.image && <img src={item.image} className="w-full h-full object-cover" alt="" />}
                                </span>
                                <span className="flex-1 min-w-0">
                                    <span className="block text-sm font-medium truncate">{item.name}</span>
                                    <span className="block text-xs text-ink-700/50">Qty {item.qty}</span>
                                    <span className="block text-sm text-gold-700">{item.price_text}</span>
                                </span>
                            </SmartLink>
                            <button
                                type="button"
                                onClick={() => remove(item.key)}
                                className="absolute top-0 right-0 p-1 text-ink-700/40 hover:text-red-600 transition"
                                title={`Remove ${item.name}`}
                                aria-label="Remove item"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    ))}
                </div>
                {items.length > 0 && (
                    <div className="border-t border-ink-100 p-5 space-y-3">
                        <div className="flex justify-between text-sm"><span className="text-ink-700/70">Subtotal</span><span>{subtotalText}</span></div>
                        {discountLines.map((d) => (
                            <div key={d.label} className="flex justify-between text-sm text-green-700"><span>{d.label}</span><span>−{d.amount_text}</span></div>
                        ))}
                        {freeShipping && (
                            <div className="flex justify-between text-sm text-green-700"><span>Free delivery</span><span>✓</span></div>
                        )}
                        {hints.map((h) => (
                            <div key={h} className="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 text-xs">🎁 {h}</div>
                        ))}
                        <Link href={urls.cart || '/cart'} onClick={() => setDrawer(false)} className="btn-outline w-full block text-center">View cart</Link>
                        <SmartLink href={urls.checkout || '/checkout'} onClick={() => setDrawer(false)} className="btn-primary w-full block text-center">Checkout</SmartLink>
                    </div>
                )}
            </div>
        </div>
    );
}
