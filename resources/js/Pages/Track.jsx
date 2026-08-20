import { useState } from 'react';
import { router } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';

const STEPS = ['Booked', 'Picked up', 'In transit', 'Delivered'];

export default function Track({ query, notFound, order, tracking }) {
    const [orderNumber, setOrderNumber] = useState(query.order_number);
    const [phone, setPhone] = useState(query.phone);

    const submit = (e) => {
        e.preventDefault();
        router.get('/track', { order_number: orderNumber, phone }, { preserveState: true });
    };

    return (
        <div className="mx-auto max-w-2xl px-4 py-12">
            <h1 className="font-display text-3xl font-semibold mb-6 text-center">Track your order</h1>

            <form onSubmit={submit} className="card p-6 grid sm:grid-cols-2 gap-4">
                <div>
                    <label className="label">Order number</label>
                    <input value={orderNumber} onChange={(e) => setOrderNumber(e.target.value)} placeholder="NOY-260615-0001" className="input" required />
                </div>
                <div>
                    <label className="label">Mobile number</label>
                    <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="01XXXXXXXXX" className="input" required />
                </div>
                <div className="sm:col-span-2"><button className="btn-primary w-full">Track</button></div>
            </form>

            {notFound && <p className="text-center text-red-600 mt-6 text-sm">No order found with those details.</p>}

            {order && (
                <div className="card p-6 mt-6">
                    <div className="flex items-center justify-between">
                        <h2 className="font-display text-xl font-semibold">{order.number}</h2>
                        <span className="badge bg-gold-100 text-gold-800 capitalize">{order.status}</span>
                    </div>

                    {tracking && (
                        <div className="mt-6 border-t border-ink-100 pt-5">
                            <div className="flex items-center justify-between mb-4">
                                <h2 className="font-medium">Courier tracking</h2>
                                <span className={`badge ${tracking.tone_class}`}>{tracking.label}</span>
                            </div>
                            <div className="flex items-center">
                                {STEPS.map((s, i) => (
                                    <div key={s} className={`flex items-center ${i === STEPS.length - 1 ? '' : 'flex-1'}`}>
                                        <div className="flex flex-col items-center">
                                            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs ${i <= tracking.step ? 'bg-gold-600 text-white' : 'bg-ink-100 text-ink-400'}`}>
                                                {i < tracking.step ? (
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                                ) : (
                                                    i + 1
                                                )}
                                            </div>
                                            <span className={`mt-1 text-[10px] text-center ${i <= tracking.step ? 'text-ink-800' : 'text-ink-400'}`}>{s}</span>
                                        </div>
                                        {i < STEPS.length - 1 && <div className={`flex-1 h-0.5 mx-1 -mt-4 ${i < tracking.step ? 'bg-gold-600' : 'bg-ink-100'}`}></div>}
                                    </div>
                                ))}
                            </div>
                            {tracking.tracking_code && (
                                <p className="mt-4 text-sm text-ink-700/70">Tracking code: <strong className="text-ink-900">{tracking.tracking_code}</strong> · via Steadfast</p>
                            )}
                        </div>
                    )}

                    <ol className="mt-6 space-y-4 border-l-2 border-gold-200 pl-4">
                        {order.history.map((h, i) => (
                            <li key={i}>
                                <div className="font-medium capitalize">{h.status}</div>
                                {h.note && <div className="text-sm text-ink-700/60">{h.note}</div>}
                                <div className="text-xs text-ink-700/40">{h.date}</div>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </div>
    );
}

Track.layout = (page) => <Layout>{page}</Layout>;
