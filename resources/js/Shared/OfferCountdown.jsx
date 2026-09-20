import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { t } from './i18n';

/**
 * The live countdown on an offer's deadline (owner, 20 Sep 2026: "I want to be
 * able to add a countdown here so customer knows it's ending soon").
 *
 * The deadline is real, not decoration: `Offer::scopeActive` drops the offer
 * the moment it passes, so the discounted prices, the product-page note and the
 * checkout discount all go with it. This is the browser's side of that — it
 * ticks, and when it reaches zero it asks the page for fresh props so a shopper
 * sitting on an open tab stops seeing a price the cart would no longer give.
 *
 * One refresh per deadline per page load, whatever is on screen: a shop grid
 * can hold a dozen of these, and a browser clock a day ahead of the server
 * would otherwise reload in a loop.
 */
const refreshed = new Set();

function refreshPrices(deadline) {
    if (refreshed.has(deadline)) return;
    refreshed.add(deadline);
    // preserveState: a chosen size, a typed checkout form and the scroll
    // position all survive; only the props (prices, notes, totals) change.
    router.reload({ preserveState: true, preserveScroll: true });
}

/** "2d 04:12:33", or "04:12:33" inside the last day. */
export function countdownText(secondsLeft) {
    const pad = (n) => String(n).padStart(2, '0');
    const days = Math.floor(secondsLeft / 86400);

    return (days > 0 ? `${days}d ` : '')
        + `${pad(Math.floor(secondsLeft / 3600) % 24)}:${pad(Math.floor(secondsLeft / 60) % 60)}:${pad(secondsLeft % 60)}`;
}

/** Seconds left on a unix deadline, ticking; null once there is nothing left. */
export function useSecondsLeft(endsAtUnix) {
    const left = () => Math.max(0, (Number(endsAtUnix) || 0) - Math.floor(Date.now() / 1000));
    const [seconds, setSeconds] = useState(left);

    useEffect(() => {
        if (!endsAtUnix) return undefined;

        setSeconds(left());
        const timer = setInterval(() => {
            const now = left();
            setSeconds(now);
            if (now <= 0) refreshPrices(endsAtUnix);
        }, 1000);

        return () => clearInterval(timer);
    }, [endsAtUnix]);

    useEffect(() => {
        // Already over when the page arrived — a tab woken after the deadline,
        // or a page served from a cache older than it.
        if (endsAtUnix && left() <= 0) refreshPrices(endsAtUnix);
    }, [endsAtUnix]);

    return endsAtUnix && seconds > 0 ? seconds : null;
}

/**
 * "Ends in 04:12:33". Renders nothing without a deadline, or once it passes.
 *
 * `compact` drops the words for tight places (a product card, a cart line).
 */
export default function OfferCountdown({ ends, lang = 'en', compact = false, className = '' }) {
    const seconds = useSecondsLeft(ends);

    if (!seconds) return null;

    const clock = countdownText(seconds);

    return (
        <span className={className} lang={lang} aria-live="off">
            {compact ? clock : t(lang, 'offer.ends', { t: clock })}
        </span>
    );
}
