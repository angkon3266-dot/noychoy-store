import { useState } from 'react';

// Personalised member offer bar (logged-in customers). Dismissal is remembered
// per message+code in localStorage, exactly like the Blade version.
export default function MemberBar({ config }) {
    const dismissed = config ? localStorage.getItem('cbar_dismissed') === config.key : true;
    const [show, setShow] = useState(!dismissed);
    const [copied, setCopied] = useState(false);
    if (!config || !show) return null;

    return (
        <div className="text-sm" style={{ background: config.bg, color: config.color }}>
            <div className="relative mx-auto max-w-7xl px-4 py-2 flex items-center justify-center gap-3 flex-wrap text-center">
                <span>{config.text}</span>
                {config.code && (
                    <button
                        type="button"
                        onClick={() => {
                            navigator.clipboard.writeText(config.code);
                            setCopied(true);
                            setTimeout(() => setCopied(false), 1500);
                        }}
                        className="inline-flex items-center gap-1 rounded-full border border-current/40 px-2.5 py-0.5 font-mono text-xs hover:bg-white/10 transition"
                    >
                        <span>{copied ? 'Copied!' : config.code}</span>
                    </button>
                )}
                {config.link && (
                    <a href={config.link} className="underline font-medium hover:no-underline">{config.linkLabel}</a>
                )}
                <button
                    type="button"
                    onClick={() => { setShow(false); localStorage.setItem('cbar_dismissed', config.key); }}
                    className="absolute right-3 opacity-70 hover:opacity-100 text-lg leading-none"
                    aria-label="Dismiss"
                >
                    ×
                </button>
            </div>
        </div>
    );
}
