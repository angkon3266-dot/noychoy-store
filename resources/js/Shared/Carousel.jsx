import { useEffect, useRef, useState } from 'react';

// Horizontal scroll-snap strip with desktop arrow paddles — React port of
// <x-carousel>. The paddles show only on hover and only on the side there is
// something left to scroll to; a thin progress line tracks the position.
export default function Carousel({ children, className = '', progress = true }) {
    const track = useRef(null);
    const [edge, setEdge] = useState({ start: true, end: false, pct: 0, scrollable: false });

    const measure = () => {
        const el = track.current;
        if (!el) return;
        const max = el.scrollWidth - el.clientWidth;
        setEdge({
            start: el.scrollLeft <= 4,
            end: el.scrollLeft >= max - 4,
            pct: max > 0 ? el.scrollLeft / max : 0,
            scrollable: max > 4,
        });
    };

    useEffect(() => {
        measure();
        const el = track.current;
        if (!el || typeof ResizeObserver === 'undefined') return undefined;
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    const scroll = (d) => {
        track.current?.scrollBy({ left: d * track.current.clientWidth * 0.85, behavior: 'smooth' });
    };

    const paddle = 'hidden md:grid place-items-center absolute top-[38%] -translate-y-1/2 z-10 w-12 h-12 rounded-full glass shadow-[0_10px_30px_-12px_rgb(0_0_0/0.35)] text-ink-900 transition duration-300 opacity-0 group-hover/rail:opacity-100 hover:scale-105 disabled:!opacity-0 disabled:pointer-events-none';

    return (
        <div className={`group/rail relative ${className}`}>
            <button type="button" onClick={() => scroll(-1)} aria-label="Scroll left" disabled={edge.start} className={`${paddle} -left-3`}>
                <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <div ref={track} onScroll={measure} className="flex gap-4 lg:gap-5 overflow-x-auto snap-x snap-mandatory scroll-smooth pb-2 no-scrollbar" style={{ WebkitOverflowScrolling: 'touch' }}>
                {children}
            </div>
            <button type="button" onClick={() => scroll(1)} aria-label="Scroll right" disabled={edge.end} className={`${paddle} -right-3`}>
                <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
            </button>
            {progress && edge.scrollable && (
                <div className="mt-6 h-px bg-ink-900/10 relative overflow-hidden rounded-full" aria-hidden="true">
                    <div className="absolute inset-y-0 left-0 w-1/4 bg-ink-900 transition-transform duration-300 ease-out" style={{ transform: `translateX(${edge.pct * 300}%)` }} />
                </div>
            )}
        </div>
    );
}
