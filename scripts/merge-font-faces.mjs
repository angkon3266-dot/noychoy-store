// Post-build fix for the bunny fonts stylesheet.
//
// laravel-vite-plugin's fonts integration emits TWO @font-face rules per
// (family, style, weight, unicode-range): one woff2, then one woff. With
// identical descriptors the CSS cascade keeps the LAST rule, so every modern
// browser downloaded the ~30% larger WOFF and the woff2 files shipped for
// nothing. The correct form is a single rule with a format-ordered src list —
// the browser then picks woff2 and falls back to woff only when it must.
//
// Runs as part of `npm run build`; rewrites public/build/assets/fonts-*.css
// in place (the filename hash no longer matches the content, which is
// harmless — the fonts manifest references it by name).

import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = join(process.cwd(), 'public', 'build', 'assets');

const files = readdirSync(dir).filter((f) => /^fonts-.*\.css$/.test(f));

for (const file of files) {
    const path = join(dir, file);
    const css = readFileSync(path, 'utf8');

    const faces = [...css.matchAll(/@font-face\s*\{[^}]*\}/g)].map((m) => m[0]);
    if (!faces.length) continue;

    const keyOf = (face) => ['font-family', 'font-style', 'font-weight', 'unicode-range']
        .map((p) => (face.match(new RegExp(`${p}:\\s*([^;]+);`)) || [])[1]?.trim() ?? '')
        .join('|');
    const srcOf = (face) => (face.match(/src:\s*([^;]+);/) || [])[1]?.trim();

    const merged = new Map();
    const order = [];
    for (const face of faces) {
        const key = keyOf(face);
        if (!merged.has(key)) {
            merged.set(key, { face, srcs: [] });
            order.push(key);
        }
        const src = srcOf(face);
        if (src) merged.get(key).srcs.push(src);
    }

    if (![...merged.values()].some((e) => e.srcs.length > 1)) {
        console.log(`[fonts] ${file}: already merged, nothing to do`);
        continue;
    }

    const out = order.map((key) => {
        const { face, srcs } = merged.get(key);
        // woff2 first so capable browsers stop there.
        const sorted = [...new Set(srcs)].sort((a, b) => (a.includes('woff2') ? -1 : 1) - (b.includes('woff2') ? -1 : 1));

        return face.replace(/src:\s*[^;]+;/, `src: ${sorted.join(', ')};`);
    }).join('\n\n');

    writeFileSync(path, out + '\n');
    console.log(`[fonts] ${file}: merged ${faces.length} @font-face rules into ${order.length} (woff2 preferred)`);
}

if (!files.length) console.log('[fonts] no fonts-*.css found, skipped');
