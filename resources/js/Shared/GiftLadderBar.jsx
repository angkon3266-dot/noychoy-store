import Icon from './Icons';

// The milestone gift progress bar — "Gift 1 of 3 unlocked, add 3 more for the
// next" with a dot per piece and a gift box on every milestone, like the
// meridianeclat.com theme bar. Data comes from CartService::giftProgress().
//
// compact = the mini-cart variant: message + thin bar, no dot track.
export default function GiftLadderBar({ gift, compact = false }) {
    if (!gift) return null;

    const maxUnits = gift.milestones[gift.milestones.length - 1] || 1;
    const pct = Math.min(100, Math.round((gift.units / maxUnits) * 100));
    const allUnlocked = gift.potential >= gift.cap && !gift.pick_needed;

    const message = gift.pick_needed ? (
        <>
            <strong>{gift.potential - gift.unlocked === 1 ? 'A free gift is' : `${gift.potential - gift.unlocked} free gifts are`} waiting</strong>
            {gift.collection ? <> — <a href={gift.collection.url} className="underline font-medium">pick from {gift.collection.name}</a> and it's yours at ৳0.</> : ' — add a gift piece and it\'s yours at ৳0.'}
        </>
    ) : allUnlocked ? (
        <><strong>All {gift.cap} gifts unlocked</strong>{gift.saved_text ? <> — {gift.saved_text} of jewelry, free.</> : '.'}</>
    ) : gift.unlocked > 0 ? (
        <><strong>Gift {gift.unlocked} of {gift.cap} unlocked</strong> — add {gift.next_more} more for the next.</>
    ) : (
        <>Add <strong>{gift.next_more} {gift.next_more === 1 ? 'piece' : 'pieces'}</strong> to unlock a <strong>free gift</strong>.</>
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

    // A dot per piece only stays readable while the track is short; an
    // unusual configuration (large buy count) falls back to a plain bar.
    const dotTrack = maxUnits <= 12;

    return (
        <div className="card p-4 mb-6">
            <div className="flex flex-col md:flex-row md:items-center gap-3 md:gap-6">
                <p className="text-sm flex items-center gap-2 md:min-w-0 md:flex-1">
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-gold-700 px-2 py-[3px] text-[10px] font-semibold uppercase tracking-[0.08em] text-white">
                        <Icon name="gift" className="w-3 h-3" strokeWidth={2} />
                        Your gift
                    </span>
                    <span className="min-w-0">{message}</span>
                </p>

                {/* Milestone track: a dot per piece, a gift box on each milestone. */}
                {!dotTrack ? (
                    <div className="md:w-[45%] shrink-0 h-2 rounded-full bg-gold-100 overflow-hidden" aria-hidden="true">
                        <div className="h-full bg-gold-600 transition-all" style={{ width: `${pct}%` }} />
                    </div>
                ) : (
                <div className="relative flex items-center md:w-[45%] shrink-0 py-2" aria-hidden="true">
                    <div className="absolute inset-x-0 top-1/2 h-px bg-gold-200" />
                    <div className="absolute left-0 top-1/2 h-px bg-gold-600 transition-all" style={{ width: `${pct}%` }} />
                    <div className="relative flex w-full justify-between items-center">
                        {Array.from({ length: maxUnits }, (_, i) => {
                            const unit = i + 1;
                            const milestone = gift.milestones.indexOf(unit);
                            const reached = gift.units >= unit;
                            if (milestone === -1) {
                                return <span key={unit} className={`h-1.5 w-1.5 rounded-full ${reached ? 'bg-gold-600' : 'bg-gold-200'}`} />;
                            }
                            return (
                                <span key={unit} className="flex flex-col items-center gap-0.5 -my-2">
                                    <span className={`grid h-7 w-7 place-items-center rounded-full border ${reached ? 'bg-gold-700 border-gold-700 text-white' : 'bg-white border-gold-300 text-gold-500'}`}>
                                        <Icon name="gift" className="w-3.5 h-3.5" strokeWidth={2} />
                                    </span>
                                    <span className={`text-[9px] uppercase tracking-wide ${reached ? 'text-gold-700 font-semibold' : 'text-ink-700/50'}`}>
                                        {ordinal(unit)}
                                    </span>
                                </span>
                            );
                        })}
                    </div>
                </div>
                )}
            </div>
        </div>
    );
}

function ordinal(n) {
    const suffix = n % 10 === 1 && n % 100 !== 11 ? 'st'
        : n % 10 === 2 && n % 100 !== 12 ? 'nd'
        : n % 10 === 3 && n % 100 !== 13 ? 'rd' : 'th';

    return `${n}${suffix}`;
}
