import { useEffect, useState } from 'react';
import SmartLink from '../SmartLink';
import { countdownText, useSecondsLeft } from '../OfferCountdown';

const DISMISSED_KEY = 'pinned-dismissed';

// The owner can drop {countdown} into the message; it becomes the live clock
// on the soonest offer deadline (App\Support\PinnedMessage). A message
// written around it is tied to it: with nothing counting down the server sends
// no message at all, and at zero this one takes itself down.
const COUNTDOWN = '{countdown}';

function Words({ text, seconds }) {
    const [before, ...rest] = text.split(COUNTDOWN);

    if (!rest.length) return <>{text}</>;

    return (
        <>
            {before}
            <span className="tabular-nums">{countdownText(seconds)}</span>
            {rest.join(COUNTDOWN)}
        </>
    );
}

// The owner's pinned message (Appearance → Pinned message; built by
// App\Support\PinnedMessage). It renders inside the sticky header, so it
// floats at the top while the page scrolls.
//
// It hides itself at its end time in the browser too: a page served from the
// LiteSpeed cache can outlive the moment the server stopped sending it.
export default function PinnedMessage({ config }) {
    const [expired, setExpired] = useState(false);
    const [dismissed, setDismissed] = useState(false);
    // The offer's clock, when the message carries one. At zero the line goes
    // with the offer — OfferCountdown has already asked the page for the
    // props that no longer carry either.
    const seconds = useSecondsLeft(config?.countdown);

    useEffect(() => {
        setExpired(false);
        const end = config?.until ? Date.parse(config.until) : NaN;
        if (Number.isNaN(end)) return undefined;
        const left = end - Date.now();
        if (left <= 0) {
            setExpired(true);
            return undefined;
        }
        // setTimeout's ceiling is ~24.8 days; anything further is re-checked on the next page.
        const timer = setTimeout(() => setExpired(true), Math.min(left, 2 ** 31 - 1));
        return () => clearTimeout(timer);
    }, [config?.until]);

    // Closed per message, not forever: the id changes with the text.
    useEffect(() => {
        if (!config?.dismissible) return;
        try {
            setDismissed(window.localStorage.getItem(DISMISSED_KEY) === config.id);
        } catch {
            // storage blocked (private mode) — the message simply stays
        }
    }, [config?.id, config?.dismissible]);

    if (!config || expired || dismissed || (config.countdown && !seconds)) return null;

    const dismiss = () => {
        setDismissed(true);
        try {
            window.localStorage.setItem(DISMISSED_KEY, config.id);
        } catch {
            // nothing to remember it in; it is gone for this page view
        }
    };

    const words = (
        <span className="font-semibold [overflow-wrap:anywhere]">
            <Words text={config.text} seconds={seconds} />
        </span>
    );

    return (
        <div className="relative text-center text-[13px] sm:text-sm leading-snug" style={{ background: config.bg, color: config.color }} data-pinned-message>
            <div className={`mx-auto max-w-7xl py-2 ${config.dismissible ? 'pl-4 pr-10' : 'px-4'}`}>
                {config.link ? (
                    <SmartLink href={config.link} className="inline-flex flex-wrap items-baseline justify-center gap-x-2 gap-y-0.5 hover:opacity-90">
                        {words}
                        {config.linkLabel && <span className="whitespace-nowrap font-medium underline underline-offset-2">{config.linkLabel} →</span>}
                    </SmartLink>
                ) : words}
            </div>
            {config.dismissible && (
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label="Close this message"
                    className="absolute right-1.5 top-1/2 -translate-y-1/2 px-2 py-1 text-base leading-none opacity-70 hover:opacity-100"
                >
                    ×
                </button>
            )}
        </div>
    );
}
