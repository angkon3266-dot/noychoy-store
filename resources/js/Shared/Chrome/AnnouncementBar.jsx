import { useState } from 'react';

// Moving announcement marquee — CSS animation lives in app.css (.announce-*).
export default function AnnouncementBar({ config }) {
    const [show, setShow] = useState(true);
    if (!config || !show) return null;

    const track = (hidden) => (
        <div className="announce-track" style={{ animationDuration: `${config.speed}s` }} aria-hidden={hidden}>
            {config.messages.map((m, i) => (
                <span key={i} className="contents">
                    <span className="announce-item">
                        {config.link ? <a href={config.link} className="hover:underline">{m}</a> : m}
                    </span>
                    <span className="announce-sep" aria-hidden="true">✦</span>
                </span>
            ))}
        </div>
    );

    return (
        <div className="announce-bar relative text-xs" style={{ background: config.bg, color: config.color }}>
            <div className="announce-marquee py-2">
                {track(false)}
                {track(true)}
            </div>
            <button
                onClick={() => setShow(false)}
                aria-label="Dismiss"
                className="absolute right-2 top-1/2 -translate-y-1/2 px-1.5 opacity-60 hover:opacity-100"
                style={{ background: config.bg }}
            >
                ×
            </button>
        </div>
    );
}
