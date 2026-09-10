import Icon from './Icons';

/** Small solid "Member" chip — the one piece of gold beside a member offer. */
export default function MemberPill() {
    return (
        <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-gold-700 px-2 py-[3px] text-[10px] font-semibold uppercase tracking-[0.08em] text-white">
            <Icon name="medal" className="w-3 h-3" strokeWidth={2} />
            Member
        </span>
    );
}
