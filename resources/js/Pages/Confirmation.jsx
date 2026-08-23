import { useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import SmartLink from '../Shared/SmartLink';
import Icon from '../Shared/Icons';

export default function Confirmation({ order, purchase, trackUrl, estimate, storePhone }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    // Purchase Pixel — eventID is the order number so Meta dedups it against
    // the server CAPI Purchase (and against a page refresh).
    useEffect(() => {
        if (window.track) {
            window.track('Purchase', {
                value: purchase.value,
                currency: 'BDT',
                content_type: 'product',
                content_ids: purchase.contentIds,
                num_items: purchase.numItems,
            }, { eventID: purchase.eventId });
        }
    }, [purchase.eventId]);

    return (
        <div className="mx-auto max-w-2xl px-4 py-12">
            <div className="card p-8 text-center">
                <div className="mx-auto w-16 h-16 rounded-full bg-success-100 flex items-center justify-center">
                    <svg className="w-8 h-8 text-success-600" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                </div>
                <h1 className="font-display text-3xl font-semibold mt-4">Thank you, {order.name}!</h1>
                <p className="text-ink-700/70 mt-2">Your order <strong>{order.number}</strong> has been placed. We'll call you shortly to confirm.</p>
                {order.isGift && (
                    <div className="mt-4 rounded-xl border border-gold-300 bg-gold-50 px-4 py-3 text-sm text-left">
                        <p className="font-semibold text-gold-800 flex items-center gap-1.5"><Icon name="gift" className="w-4 h-4 shrink-0" /> Packed as a gift</p>
                        <p className="text-ink-700/70 mt-0.5">
                            No price slip goes in the box.
                            {order.cardMessage ? <> Your card will read: <em>“{order.cardMessage}”</em></> : ' You can still call us to add a card message.'}
                        </p>
                    </div>
                )}

                <div className="text-left mt-8 border-t border-ink-100 pt-6 space-y-3">
                    {order.items.map((item, i) => (
                        <div key={i} className="flex justify-between text-sm">
                            <span>{item.name} <span className="text-ink-700/70">× {item.qty}</span></span>
                            <span className="font-medium">{item.subtotalText}</span>
                        </div>
                    ))}
                    <dl className="border-t border-ink-100 pt-3 space-y-1 text-sm">
                        <div className="flex justify-between"><dt className="text-ink-700/70">Subtotal</dt><dd>{order.subtotalText}</dd></div>
                        {order.discountText && <div className="flex justify-between text-success-700"><dt>Discount</dt><dd>−{order.discountText}</dd></div>}
                        <div className="flex justify-between"><dt className="text-ink-700/70">Shipping</dt><dd>{order.shippingText}</dd></div>
                        <div className="flex justify-between font-semibold text-base"><dt>Total (COD)</dt><dd>{order.totalText}</dd></div>
                    </dl>
                </div>

                {/* What actually happens next. Without this the page ends the
                    conversation at "thank you" — and a cash-on-delivery buyer
                    still has two things to do: take a call, and have the money
                    ready on the day. Both are worth saying plainly. */}
                <div className="text-left mt-6 rounded-xl border border-gold-200 bg-gold-50/60 p-5">
                    <h2 className="font-semibold text-sm">What happens next</h2>
                    <ol className="mt-3 space-y-3 text-sm">
                        <li className="flex gap-3">
                            <span aria-hidden="true" className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-gold-200 text-[11px] font-semibold text-gold-800">1</span>
                            <span className="min-w-0">
                                We call you to confirm the order
                                {storePhone && <> — from <strong className="whitespace-nowrap">{storePhone}</strong></>}.
                            </span>
                        </li>
                        {estimate && (
                            <li className="flex gap-3">
                                <span aria-hidden="true" className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-gold-200 text-[11px] font-semibold text-gold-800">2</span>
                                <span className="min-w-0">
                                    The courier delivers <strong>{estimate.label}</strong>
                                    <span className="text-ink-700/70"> ({estimate.zoneText}). Fridays excluded.</span>
                                </span>
                            </li>
                        )}
                        <li className="flex gap-3">
                            <span aria-hidden="true" className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-gold-200 text-[11px] font-semibold text-gold-800">{estimate ? 3 : 2}</span>
                            <span className="min-w-0">
                                Keep <strong className="whitespace-nowrap">{order.totalText}</strong> ready for the courier — payment is on delivery.
                            </span>
                        </li>
                    </ol>
                </div>

                <div className="mt-8 flex justify-center gap-3">
                    <Link href={urls.shop || '/shop'} className="btn-primary">Continue shopping</Link>
                    <SmartLink href={trackUrl} className="btn-outline">Track order</SmartLink>
                </div>
            </div>
        </div>
    );
}

Confirmation.layout = (page) => <Layout>{page}</Layout>;
