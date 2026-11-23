import { useEffect, useState } from 'react';

/*
 * The colour of a piece, read off its photo: the average of the pixels that
 * are not background (near-white or near-black), with the saturation lifted a
 * little so gold reads as gold. Used to draw bangles and rings on the stacker
 * when the owner has not uploaded a cut-out. Same-origin photos only — a
 * cross-origin one taints the canvas, and the piece falls back to gold.
 */
const cache = new Map();
const FALLBACK = { r: 201, g: 162, b: 92 };

function sample(url) {
    if (cache.has(url)) return cache.get(url);
    const p = new Promise((resolve) => {
        const img = new Image();
        img.decoding = 'async';
        img.onload = () => {
            try {
                const n = 48;
                const c = document.createElement('canvas');
                c.width = n; c.height = n;
                const ctx = c.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(img, 0, 0, n, n);
                const d = ctx.getImageData(0, 0, n, n).data;
                let r = 0, g = 0, b = 0, k = 0;
                for (let i = 0; i < d.length; i += 4) {
                    const [R, G, B, A] = [d[i], d[i + 1], d[i + 2], d[i + 3]];
                    const max = Math.max(R, G, B), min = Math.min(R, G, B);
                    if (A < 128 || max > 238 || max < 28) continue;      // background, shadow
                    const w = 1 + (max - min) / 64;                       // colourful pixels count more
                    r += R * w; g += G * w; b += B * w; k += w;
                }
                if (!k) return resolve(FALLBACK);
                resolve(saturate({ r: r / k, g: g / k, b: b / k }, 1.25));
            } catch (e) {
                resolve(FALLBACK);
            }
        };
        img.onerror = () => resolve(FALLBACK);
        img.src = url;
    });
    cache.set(url, p);
    return p;
}

function saturate({ r, g, b }, f) {
    const avg = (r + g + b) / 3;
    const c = (v) => Math.max(0, Math.min(255, Math.round(avg + (v - avg) * f)));
    return { r: c(r), g: c(g), b: c(b) };
}

export function shade({ r, g, b }, amt) {
    const c = (v) => Math.max(0, Math.min(255, Math.round(amt >= 0 ? v + (255 - v) * amt : v * (1 + amt))));
    return `rgb(${c(r)}, ${c(g)}, ${c(b)})`;
}

/** { colourOf(piece) } — a map that fills in as photos are sampled. */
export function usePieceColours(pieces) {
    const [colours, setColours] = useState({});
    const key = pieces.map((p) => p.id).join(',');

    useEffect(() => {
        let live = true;
        pieces.forEach((p) => {
            if (!p.draw || colours[p.id]) return;
            sample(p.draw).then((c) => live && setColours((m) => (m[p.id] ? m : { ...m, [p.id]: c })));
        });
        return () => { live = false; };
    }, [key]); // eslint-disable-line react-hooks/exhaustive-deps

    return (p) => colours[p.id] || FALLBACK;
}

export const SKIN_TONES = [
    { key: 'fair', label: 'Fair', base: '#f3d3bd', shadow: '#dfb196' },
    { key: 'wheat', label: 'Wheatish', base: '#dfae88', shadow: '#c58d66' },
    { key: 'tan', label: 'Tan', base: '#b97f57', shadow: '#9a643f' },
    { key: 'deep', label: 'Deep', base: '#7c4f33', shadow: '#603a23' },
];
