import SmartLink from './SmartLink';

// Small pieces both homepage designs (Couture and Editorial) are built from.

export const Arrow = ({ className = 'w-4 h-4' }) => (
    <svg aria-hidden="true" className={className} fill="none" stroke="currentColor" strokeWidth="1.6" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
);

/* ── Shared section heading ─────────────────────────────────────────────── */
export function Heading({ eyebrow, title, subtitle = null, action = null, center = false, dark = false }) {
    return (
        <div className={`mb-10 lg:mb-14 ${center ? 'text-center max-w-2xl mx-auto' : 'flex flex-wrap items-end justify-between gap-x-10 gap-y-4'}`}>
            <div className={center ? '' : 'max-w-2xl'}>
                {eyebrow && (
                    <p className={`eyebrow flex items-center gap-3 ${center ? 'justify-center' : ''} ${dark ? '!text-white/60' : ''}`}>
                        <span className={`h-px w-8 ${dark ? 'bg-white/40' : 'bg-ink-900/25'}`} aria-hidden="true" />
                        {eyebrow}
                    </p>
                )}
                <h2 className={`display mt-4 text-[2.1rem] sm:text-5xl lg:text-[3.5rem] ${dark ? '!text-white' : ''}`}>{title}</h2>
                {subtitle && <p className={`mt-4 text-[15px] leading-relaxed ${dark ? 'text-white/65' : 'text-ink-700/75'}`}>{subtitle}</p>}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

export function ViewAll({ href, children, dark = false }) {
    return (
        <SmartLink href={href} className={`lux-arrow group/va inline-flex items-center gap-3 text-[12px] font-medium tracking-[0.12em] uppercase ${dark ? 'text-white' : 'text-ink-900'}`}>
            <span className="lux-link lux-link-on">{children}</span>
            <span className={`grid place-items-center w-9 h-9 rounded-full border transition-colors duration-300 ${dark ? 'border-white/30 group-hover/va:bg-white group-hover/va:text-ink-900' : 'border-ink-900/15 group-hover/va:bg-ink-900 group-hover/va:text-white group-hover/va:border-ink-900'}`}>
                <Arrow className="w-3.5 h-3.5" />
            </span>
        </SmartLink>
    );
}

export const shell = 'mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-10';
