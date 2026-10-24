import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';

// Type-ahead product search (desktop dropdown + mobile bar) — same
// /search/suggest contract as the Alpine searchBox component.
export default function SearchBox({ compact = false, lux = false, autoFocus = false, onNavigate }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const timer = useRef(null);
    const boxRef = useRef(null);

    useEffect(() => {
        const close = (e) => {
            if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    const onInput = (value) => {
        setQ(value);
        clearTimeout(timer.current);
        if (value.trim().length < 2) {
            setResults([]);
            setOpen(false);
            return;
        }
        setLoading(true);
        timer.current = setTimeout(async () => {
            try {
                const res = await fetch(`${urls.suggest || '/search/suggest'}?q=${encodeURIComponent(value)}`);
                setResults(await res.json());
                setOpen(true);
            } catch (e) {
                setResults([]);
            }
            setLoading(false);
        }, 250);
    };

    return (
        <div className="relative" ref={boxRef}>
            <form action={urls.shop || '/shop'} method="GET" role="search" className={lux ? 'relative' : ''}>
                {lux && (
                    <svg aria-hidden="true" className="absolute left-0 top-1/2 -translate-y-1/2 w-6 h-6 text-ink-900/40" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path strokeLinecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                )}
                <input
                    name="q"
                    value={q}
                    onChange={(e) => onInput(e.target.value)}
                    onFocus={() => q.length >= 2 && setOpen(true)}
                    aria-label="Search jewelry"
                    placeholder={lux ? 'Search earrings, necklaces, rings…' : 'Search jewelry…'}
                    autoComplete="off"
                    autoFocus={autoFocus}
                    className={lux
                        ? 'w-full bg-transparent border-0 border-b border-ink-900/15 focus:border-ink-900 focus:ring-0 focus:outline-none pl-10 pr-2 py-3 font-display text-2xl md:text-4xl tracking-[-0.02em] text-ink-900 placeholder:text-ink-900/30 transition-colors'
                        : (compact ? 'input py-1.5 w-44' : 'input py-2 w-full')}
                />
            </form>
            {lux && open && results.length > 0 && (
                <div className="mt-6 grid grid-cols-2 sm:grid-cols-3 gap-3 max-h-[55vh] overflow-y-auto no-scrollbar">
                    {results.map((r) => (
                        <a key={r.url} href={r.url} onClick={onNavigate} className="group flex items-center gap-3 rounded-2xl bg-mist-50 p-2 pr-3 hover:bg-white hover:shadow-[0_10px_30px_-18px_rgb(0_0_0/0.4)] transition">
                            <span className="w-14 h-14 rounded-xl bg-mist-100 overflow-hidden shrink-0">
                                {r.thumb && <img src={r.thumb} className="w-full h-full object-cover transition duration-500 group-hover:scale-105" alt="" />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-[13px] leading-snug line-clamp-2 text-ink-900">{r.name}</span>
                                <span className="block text-xs font-semibold text-ink-900/70 mt-0.5">{r.price}</span>
                            </span>
                        </a>
                    ))}
                </div>
            )}
            {!lux && open && results.length > 0 && (
                <div className={`absolute ${compact ? 'right-0 w-80' : 'inset-x-0'} mt-1 max-h-96 overflow-y-auto rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-2`}>
                    {results.map((r) => (
                        <a key={r.url} href={r.url} onClick={onNavigate} className="flex items-center gap-3 rounded-lg p-2 hover:bg-mist-50">
                            <span className="w-10 h-10 rounded-lg bg-mist-100 overflow-hidden shrink-0">
                                {r.thumb && <img src={r.thumb} className="w-full h-full object-cover" alt="" />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm truncate">{r.name}</span>
                                <span className="block text-xs font-semibold text-ink-900/70">{r.price}</span>
                            </span>
                        </a>
                    ))}
                </div>
            )}
            {open && !results.length && !loading && q.length >= 2 && (
                <div className={lux ? 'mt-5 text-sm text-ink-700/70' : `absolute ${compact ? 'right-0 w-80' : 'inset-x-0'} mt-1 rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-3 text-sm text-ink-700/70`}>
                    No matches — press Enter to search all.
                </div>
            )}
        </div>
    );
}
