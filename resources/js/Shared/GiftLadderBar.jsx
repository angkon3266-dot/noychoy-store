import Icon from './Icons';

// The reward ladder — "add more, save more". Every paid piece climbs one
// rung, and each rung's reward stays unlocked as the cart grows: ৳50 off at
// the 1st, 2% off at the 2nd, free delivery at the 3rd … a free gift at the
// 9th. Data comes from CartService::giftProgress().
//
// compact = the mini-cart variant: message + thin bar, no rung track.
export default function GiftLadderBar({ gift, compact = false }) {
    if (!gift || !gift.tiers?.length) return null;

    const last = gift.tiers[gift.tiers.length - 1].threshold || 1;
    const pct = Math.min(100, Math.round((gift.units / last) * 100));
    const done = !gift.next;

    const message = gift.gift?.pick_needed ? (
        <>
            <strong>A free gift is waiting</strong>
            {gift.gift.collection
                ? <> — <a href={gift.gift.collection.url} className="underline font-medium">pick from {gift.gift.collection.name}</a> and it's yours at ৳0.</>
                : ' — add a gift piece and it\'s yours at ৳0.'}
        </>
    ) : done ? (
        <><strong>All {gift.count} rewards unlocked</strong>{gift.saved_text ? <> — {gift.saved_text} saved on this order.</> : '.'}</>
    ) : gift.tier > 0 ? (
        <><strong>{gift.summary}</strong> unlocked — add <strong>{gift.next.more} more</strong> for {phrase(gift.next.label)}.</>
    ) : (
        <>Add <strong>{gift.next.more} {gift.next.more === 1 ? 'piece' : 'pieces'}</strong> to unlock <strong>{phrase(gift.next.label)}</strong>.</>
    );

    if (compact) {
        return (
            <div className="rounded-md bg-gold-100/70 px-3 py-2 text-xs text-ink-800">
                <p className="flex items-start gap-1.5">
                    <Icon name="gift" className="w-3.5 h-3.5 shrink-0 mt-[1px] text-gold-700" />
                    <span>{message}</span>
                </p>
                <div className="mt-1.5 h-1 rounded-full bg-white/70 overflow-hidden">
                    <div className="h-full bg-gold-600 transition-all" style={{ width: `${pct}%` }} />
                </div>
            </div>
        );
    }

    return (
        <div className="card p-4 mb-6">
            <div className="flex flex-col md:flex-row md:items-center gap-3 md:gap-6">
                <p className="text-sm flex items-center gap-2 md:min-w-0 md:flex-1">
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-gold-700 px-2 py-[3px] text-[10px] font-semibold uppercase tracking-[0.08em] text-white">
                        <Icon name="gift" className="w-3 h-3" strokeWidth={2} />
                        Rewards
                    </span>
                    <span className="min-w-0">{message}</span>
                </p>

                {/* The rung track: one marker per rung, captioned with its reward. */}
                <div className="relative flex items-center md:w-[52%] shrink-0 py-2" aria-hidden="true">
                    <div className="absolute inset-x-0 top-1/2 h-px bg-gold-200" />
                    <div className="absolute left-0 top-1/2 h-px bg-gold-600 transition-all" style={{ width: `${pct}%` }} />
                    <div className="relative flex w-full justify-between items-start">
                        {gift.tiers.map((t) => {
                            const isNext = gift.next?.n === t.n;
                            const ring = t.unlocked
                                ? 'bg-gold-700 border-gold-700 text-white'
                                : isNext
                                    ? 'bg-white border-gold-600 text-gold-700 ring-2 ring-gold-200'
                                    : 'bg-white border-gold-300 text-gold-500';

                            return (
                                <span key={t.n} className="flex flex-col items-center gap-0.5 -my-2" title={`${t.threshold} ${t.threshold === 1 ? 'piece' : 'pieces'}: ${t.label}`}>
                                    <span className={`grid h-6 w-6 place-items-center rounded-full border text-[10px] font-semibold ${ring}`}>
                                        {t.type === 'free_gift'
                                            ? <Icon name="gift" className="w-3 h-3" strokeWidth={2} />
                                            : t.type === 'free_delivery'
                                                ? <Icon name="truck" className="w-3 h-3" strokeWidth={2} />
                                                : t.threshold}
                                    </span>
                                    <span className={`text-[9px] leading-tight text-center ${t.unlocked ? 'text-gold-700 font-semibold' : 'text-ink-500'}`}>{t.short}</span>
                                </span>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}

// "Free gift" reads as "a free gift" mid-sentence; money and delivery labels
// just drop their capital.
function phrase(label) {
    if (/^free gift/i.test(label)) return 'a free gift';

    return label.charAt(0).toLowerCase() + label.slice(1);
}
