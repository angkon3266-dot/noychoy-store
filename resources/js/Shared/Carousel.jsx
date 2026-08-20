import { useRef } from 'react';

// Horizontal scroll-snap strip with desktop arrow paddles — React port of
// <x-carousel>.
export default function Carousel({ children, className = '' }) {
    const track = useRef(null);

    const scroll = (d) => {
        track.current?.scrollBy({ left: d * track.current.clientWidth * 0.85, behavior: 'smooth' });
    };

    return (
        <div className={`relative ${className}`}>
            <button type="button" onClick={() => scroll(-1)} aria-label="Scroll left"
                className="hidden md:grid place-items-center absolute left-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-ink-100 text-lg hover:bg-ink-50">‹</button>
            <div ref={track} className="flex gap-4 overflow-x-auto snap-x scroll-smooth pb-2" style={{ scrollbarWidth: 'none', msOverflowStyle: 'none', WebkitOverflowScrolling: 'touch' }}>
                {children}
            </div>
            <button type="button" onClick={() => scroll(1)} aria-label="Scroll right"
                className="hidden md:grid place-items-center absolute right-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-ink-100 text-lg hover:bg-ink-50">›</button>
        </div>
    );
}
