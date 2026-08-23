import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';

// Type-ahead product search (desktop dropdown + mobile bar) — same
// /search/suggest contract as the Alpine searchBox component.
export default function SearchBox({ compact = false, autoFocus = false, onNavigate }) {
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
            <form action={urls.shop || '/shop'} method="GET" role="search">
                <input
                    name="q"
                    value={q}
                    onChange={(e) => onInput(e.target.value)}
                    onFocus={() => q.length >= 2 && setOpen(true)}
                    aria-label="Search jewelry"
                    placeholder="Search jewelry…"
                    autoComplete="off"
                    autoFocus={autoFocus}
                    className={compact ? 'input py-1.5 w-44' : 'input py-2 w-full'}
                />
            </form>
            {open && results.length > 0 && (
                <div className={`absolute ${compact ? 'right-0 w-80' : 'inset-x-0'} mt-1 max-h-96 overflow-y-auto rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-2`}>
                    {results.map((r) => (
                        <a key={r.url} href={r.url} onClick={onNavigate} className="flex items-center gap-3 rounded-lg p-2 hover:bg-gold-50">
                            <span className="w-10 h-10 rounded bg-gold-100 overflow-hidden shrink-0">
                                {r.thumb && <img src={r.thumb} className="w-full h-full object-cover" alt="" />}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm truncate">{r.name}</span>
                                <span className="block text-xs text-gold-700">{r.price}</span>
                            </span>
                        </a>
                    ))}
                </div>
            )}
            {open && !results.length && !loading && q.length >= 2 && (
                <div className={`absolute ${compact ? 'right-0 w-80' : 'inset-x-0'} mt-1 rounded-xl border border-ink-100 bg-white shadow-xl z-50 p-3 text-sm text-ink-700/70`}>
                    No matches — press Enter to search all.
                </div>
            )}
        </div>
    );
}
