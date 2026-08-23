import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import Icon from '../Shared/Icons';

// Where the post-delivery review request lands.
//
// This exists rather than deep-linking to the product page's #reviews section
// because that form starts collapsed inside a collapsed section, and it cannot
// prefill the buyer's phone — so a review arriving from our own request would
// come back unverified. Here the phone travels as a hidden field, which is what
// earns the "Verified buyer" badge on the way in.
export default function ReviewInvite({ order, items, perk }) {
    const { props } = usePage();

    // Which products this browser has just submitted, so the card can flip to
    // a thank-you without a round trip. The server-side `done` flag covers
    // reviews left on an earlier visit.
    const [justDone, setJustDone] = useState([]);
    const remaining = items.filter((i) => !i.done && !justDone.includes(i.productId));

    return (
        <div className="mx-auto max-w-3xl px-4 py-10">
            <h1 className="font-display text-3xl font-semibold">Rate your order</h1>
            <p className="mt-2 text-ink-700/70">
                Order <span className="font-mono">{order.number}</span> — thank you, {order.name}.
                {' '}Your rating is the thing the next shopper reads first.
            </p>

            {perk > 0 && (
                <p className="mt-3 inline-flex items-center gap-1.5 rounded-full bg-gold-100 px-3 py-1 text-sm text-gold-800">
                    <Icon name="sparkle" className="w-4 h-4 shrink-0" />
                    Earn {perk.toLocaleString()} points for each review we approve
                </p>
            )}

            {props.flash?.success && (
                <div className="mt-6 rounded-md bg-success-50 border border-success-200 text-success-800 px-4 py-3 text-sm">{props.flash.success}</div>
            )}

            {items.length === 0 && (
                <p className="mt-8 text-ink-700/60">There is nothing left to review on this order.</p>
            )}

            {items.length > 0 && remaining.length === 0 && (
                <div className="mt-8 rounded-xl border border-success-200 bg-success-50 p-6 text-center">
                    <p className="font-medium text-success-800">That is everything — thank you.</p>
                    <p className="mt-1 text-sm text-success-700/80">Reviews appear on the product page once approved.</p>
                </div>
            )}

            <div className="mt-8 space-y-5">
                {items.map((item) => (
                    <ReviewCard
                        key={item.productId}
                        item={item}
                        order={order}
                        done={item.done || justDone.includes(item.productId)}
                        onDone={() => setJustDone((d) => [...d, item.productId])}
                    />
                ))}
            </div>
        </div>
    );
}

function ReviewCard({ item, order, done, onDone }) {
    const { props } = usePage();
    const [rating, setRating] = useState(0);
    const [hover, setHover] = useState(0);
    const [busy, setBusy] = useState(false);
    const errors = props.errors || {};
    const errorMsg = Object.values(errors)[0]?.[0];

    const submit = (e) => {
        e.preventDefault();
        setBusy(true);
        // Same endpoint and the same multipart shape the product page posts.
        router.post(item.reviewUrl, new FormData(e.target), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onDone(),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="card p-5">
            <div className="flex items-start gap-4">
                {item.image
                    ? <img src={item.image} alt="" className="w-20 h-20 rounded-lg object-cover border border-ink-100 shrink-0" />
                    : <div className="w-20 h-20 rounded-lg bg-gold-100 shrink-0" />}
                <div className="min-w-0 flex-1">
                    <a href={item.url} className="font-medium hover:underline">{item.name}</a>

                    {done ? (
                        <p className="mt-2 inline-flex items-center gap-1.5 text-sm text-success-700">
                            <Icon name="check" className="w-4 h-4 shrink-0" />Reviewed — thank you
                        </p>
                    ) : (
                        <form onSubmit={submit} className="mt-3 space-y-3" encType="multipart/form-data">
                            {/* The phone is what earns the Verified buyer badge:
                                the endpoint checks it against the orders table. */}
                            <input type="hidden" name="phone" value={order.phone || ''} />

                            <div>
                                <span className="label">Your rating *</span>
                                <div className="flex gap-1" onMouseLeave={() => setHover(0)}>
                                    {[1, 2, 3, 4, 5].map((i) => (
                                        <button
                                            key={i}
                                            type="button"
                                            onClick={() => setRating(i)}
                                            onMouseEnter={() => setHover(i)}
                                            aria-label={`${i} star${i > 1 ? 's' : ''}`}
                                            aria-pressed={rating === i}
                                            className={`text-2xl transition ${(hover || rating) >= i ? 'text-gold-500' : 'text-ink-200'}`}
                                        >★</button>
                                    ))}
                                </div>
                                <input type="hidden" name="rating" value={rating || ''} required />
                            </div>

                            <input name="author_name" placeholder="Your name *" className="input" required defaultValue={order.name || ''} />
                            <input name="title" placeholder="Headline (optional)" className="input" />
                            <textarea name="body" rows={3} placeholder="How does it look? How did it wear?" className="input" />
                            <div>
                                <label className="label text-xs" htmlFor={`photos-${item.productId}`}>Add photos (optional, up to 4)</label>
                                <input id={`photos-${item.productId}`} type="file" name="photos[]" accept="image/*" multiple className="input text-sm" />
                            </div>

                            {errorMsg && <div className="text-sm text-danger-700 bg-danger-50 rounded p-2">{errorMsg}</div>}

                            <button className="btn-primary" disabled={!rating || busy}>
                                {busy ? 'Sending…' : 'Submit review'}
                            </button>
                            <p className="text-xs text-ink-700/50">Reviews appear after approval.</p>
                        </form>
                    )}
                </div>
            </div>
        </div>
    );
}

ReviewInvite.layout = (page) => <Layout>{page}</Layout>;
