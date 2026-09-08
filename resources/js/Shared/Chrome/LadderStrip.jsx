import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useCart } from '../CartContext';
import Icon from '../Icons';
import { rewardPhrase, rewardLabel, t } from '../i18n';

// The always-visible reward ladder: one slim line at the foot of the sticky
// header on every page, so the next reward stays in view while browsing.
// Rungs auto-fit the space they have: when they cannot all fit, the first
// few, an ellipsis and the last one are shown. A phone is always capped at
// 1–5 … 10, even when ten would fit, so the strip stays one slim row.
//
// Data: the live cart state when the drawer/add-to-cart has refreshed it,
// else the server's snapshot shared on every Inertia response (props.ladder).
export default function LadderStrip() {
    const { props } = usePage();
    const lang = props.chrome?.lang || 'en';
    const urls = props.chrome?.urls || {};
    const { gift } = useCart();
    const ladder = gift || props.ladder;

    const trackRef = useRef(null);
    const [fit, setFit] = useState(12);

    useEffect(() => {
        const el = trackRef.current;
        if (!el || typeof ResizeObserver === 'undefined') return undefined;
        const measure = () => {
            const room = Math.floor(el.clientWidth / 30);
            const cap = window.matchMedia('(max-width: 639px)').matches ? 7 : 99;   // 5 rungs + … + last
            setFit(Math.max(3, Math.min(room, cap)));
        };
        measure();
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, [ladder?.count]);

    if (!ladder || !ladder.tiers?.length) return null;

    const tiers = ladder.tiers;
    const total = tiers.length;
    const next = ladder.next;
    // Always keep the last rung in view: it is the destination.
    const visible = fit >= total
        ? tiers
        : [...tiers.slice(0, Math.max(2, fit - 2)), null, tiers[total - 1]];

    const message = ladder.gift?.pick_needed
        ? t(lang, 'strip.gift')
        : !next
            ? t(lang, 'strip.all', { n: ladder.count, saved: ladder.saved_text ? t(lang, 'strip.saved', { saved: ladder.saved_text }) : '' })
            : ladder.tier > 0
                ? t(lang, 'strip.next', { unlocked: ladder.summary.split(' · ').map((l) => rewardLabel(lang, l)).join(' · '), more: next.more, reward: rewardPhrase(lang, next.label) })
                : t(lang, 'strip.first', { more: next.more, pieces: t(lang, next.more === 1 ? 'piece' : 'pieces'), reward: rewardPhrase(lang, next.label) });

    return (
        <a href={urls.cart || '/cart'} className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-gold-200/70 py-1.5 text-[12px] leading-tight text-ink-800 hover:text-ink-900" data-ladder-strip aria-label={`${t(lang, 'strip.badge')}: ${message}`}>
            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-gold-700 px-2 py-[2px] text-[10px] font-semibold uppercase tracking-[0.08em] text-white">
                <Icon name="gift" className="w-3 h-3" strokeWidth={2} />
                {t(lang, 'strip.badge')}
            </span>
            {/* A phone gets one short Bangla promise and nothing else (the owner's
                call: "clean thakbe"); the live message is for sm and up. */}
            <span className="min-w-0 flex-1 basis-40 truncate">
                <span className="sm:hidden font-medium" lang="bn">প্রতি পিসেই রিওয়ার্ড</span>
                <span className="hidden sm:inline" lang={lang}>{message}</span>
            </span>
            <span ref={trackRef} className="flex basis-full items-center justify-between sm:basis-[46%] md:basis-[40%]" aria-hidden="true">
                {visible.map((tier, i) => tier === null ? (
                    <span key="gap" className="text-[10px] text-ink-500 px-0.5">…</span>
                ) : (
                    <span key={tier.n} className="flex flex-col items-center" title={`${tier.threshold}: ${tier.label}`}>
                        <span className={`grid h-5 w-5 place-items-center rounded-full border text-[9px] font-semibold ${tier.unlocked
                            ? 'bg-gold-700 border-gold-700 text-white'
                            : next?.n === tier.n ? 'bg-white border-gold-600 text-gold-700 ring-2 ring-gold-200' : 'bg-white border-gold-300 text-gold-500'}`}>
                            {tier.type === 'free_gift' ? <Icon name="gift" className="w-2.5 h-2.5" strokeWidth={2.2} /> : tier.type === 'free_delivery' ? <Icon name="truck" className="w-2.5 h-2.5" strokeWidth={2.2} /> : tier.threshold}
                        </span>
                        <span className={`hidden md:block text-[8px] leading-none mt-0.5 ${tier.unlocked ? 'text-gold-700 font-semibold' : 'text-ink-500'}`}>{rewardLabel(lang, tier.short)}</span>
                    </span>
                ))}
            </span>
        </a>
    );
}
