import { Link, router } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import TrackingProgress from '../../Shared/TrackingProgress';

export default function OrderDetail({ order, tracking, reorderUrl }) {
    return (
        <div className="mx-auto max-w-2xl px-4 py-10">
            <Link href="/account/orders" className="text-sm text-gold-700 hover:underline">← Back to orders</Link>
            <div className="card p-6 mt-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-display text-2xl font-semibold">{order.number}</h1>
                    <span className="badge bg-gold-100 text-gold-800 capitalize">{order.status}</span>
                </div>
                <p className="text-sm text-ink-700/60 mt-1">{order.date}</p>

                <div className="mt-6 space-y-3">
                    {order.items.map((item, i) => (
                        <div key={i} className="flex justify-between text-sm">
                            <span>{item.name} <span className="text-ink-700/50">× {item.qty}</span></span>
                            <span className="font-medium">{item.subtotalText}</span>
                        </div>
                    ))}
                </div>
                <dl className="border-t border-ink-100 mt-4 pt-4 space-y-1 text-sm">
                    <div className="flex justify-between"><dt className="text-ink-700/70">Subtotal</dt><dd>{order.subtotalText}</dd></div>
                    {order.discountText && <div className="flex justify-between text-success-700"><dt>Discount</dt><dd>−{order.discountText}</dd></div>}
                    <div className="flex justify-between"><dt className="text-ink-700/70">Shipping</dt><dd>{order.shippingText}</dd></div>
                    <div className="flex justify-between font-semibold text-base"><dt>Total</dt><dd>{order.totalText}</dd></div>
                </dl>

                <div className="mt-6 text-sm">
                    <h2 className="font-medium mb-1">Delivery address</h2>
                    <p className="text-ink-700/70">{order.address.name}, {order.address.phone}<br />{order.address.line}</p>
                </div>

                <TrackingProgress tracking={tracking} />

                <button type="button" onClick={() => router.post(reorderUrl)} className="btn-outline w-full mt-6">Reorder these items</button>
            </div>
        </div>
    );
}

OrderDetail.layout = (page) => <Layout>{page}</Layout>;
