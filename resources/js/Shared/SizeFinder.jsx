import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from './Icons';
import { trapTab } from './focusTrap';

/*
 * "Find my size" — the ring and bangle size finder on the product page
 * (Nov 2026; the idea is the on-screen sizer from the navira.ink jewellery
 * samples). App\Support\Storefront\SizeGuide decides which products get it and
 * which option it may pick.
 *
 * Three ways to a size, because a gift buyer on a phone has only some of them:
 *  - On screen: calibrate once with any bank / NID card (all are ISO ID-1,
 *    53.98 mm on the short edge), then lay a ring — or a bangle on a big
 *    enough screen — on a circle and size the circle to its inside edge.
 *  - Measure: a paper strip or thread round the finger (or round the hand,
 *    thumb tucked in, for a bangle), in millimetres.
 *  - Chart: the sizes with their inside diameter and circumference.
 * The answer then selects the matching option on the page, if this piece comes
 * in it and it is in stock.
 *
 * The sizes are the store's own chart (Admin → Products → Size chart, sent as
 * guide.chart: each size with its measurement round the finger or hand), so a
 * supplier whose "7" is 60 mm round is answered as a 7. See
 * App\Support\Storefront\SizeChart.
 */

const CARD_SHORT_MM = 53.98;
const PX_KEY = 'nc_px_per_mm';
const DEFAULT_PX_PER_MM = 96 / 25.4; // a CSS pixel as specified; calibration fixes the rest

const fmt = (n, d = 1) => Number(n).toFixed(d).replace(/\.0+$/, '');
const mm1 = (n) => Number(n).toFixed(1); // millimetres keep their decimal, so the column lines up

/** The number a value carries: "US 7.5" → "7.5", "Size 2.10" → "2.10". */
const numberIn = (value) => (String(value).match(/\d+(?:\.\d+)?/) || [null])[0];

/** A bare ring number reads as a US size; anything else as the owner typed it. */
const sizeLabel = (kind, size) => (kind === 'ring' && /^\d+(\.\d+)?$/.test(String(size)) ? `US ${size}` : String(size));

/**
 * Whether a chart size and a product option are the same size. Bangles compare
 * as written ("2.10" is not "2.1"); rings by number ("7" is "7.0").
 */
const sameSize = (kind, a, b) => a !== null && b !== null
    && (a === b || (kind === 'ring' && Math.abs(parseFloat(a) - parseFloat(b)) < 0.01));

/** The chart rows, smallest first, each with its inside diameter. */
function chartRows(chart) {
    return (chart || [])
        .map((r) => ({ size: String(r.size), around: Number(r.around), mm: Number(r.around) / Math.PI, num: numberIn(r.size) }))
        .filter((r) => r.around > 0)
        .sort((a, b) => a.around - b.around);
}

function readPxPerMm() {
    try {
        const v = parseFloat(window.localStorage.getItem(PX_KEY));
        return v > 1 && v < 20 ? v : null;
    } catch (e) {
        return null;
    }
}

function savePxPerMm(v) {
    try { window.localStorage.setItem(PX_KEY, String(v)); } catch (e) { /* private mode */ }
}

/**
 * The size a measured inside diameter calls for on the store's chart, plus the
 * option on this product that matches it.
 *
 * Between two sizes it goes up, never down: a ring a touch loose stays on, one
 * a touch tight does not go over the knuckle, and a bangle must clear the hand.
 * So it is the smallest size at least as big as the measurement, give or take
 * half a millimetre round a finger (a millimetre round a hand) of tape slack.
 */
function recommend(kind, mm, options, rows) {
    if (!(mm > 0) || rows.length === 0) return null;

    const around = mm * Math.PI;
    const slack = kind === 'bangle' ? 1.0 : 0.5;
    const wantAt = rows.findIndex((r) => r.around >= around - slack);
    const want = wantAt >= 0 ? rows[wantAt] : null;

    const offered = options
        .map((o) => ({ ...o, at: rows.findIndex((r) => sameSize(kind, r.num, o.num)) }))
        .filter((o) => o.at >= 0);
    // Only a size at least as big as the finger. Offering the largest one
    // when every size is too small put a "Choose" button on a ring that would
    // not go over the knuckle — a returned parcel, both ways paid by the store.
    const fit = want ? offered.filter((o) => o.at >= wantAt).sort((a, b) => a.at - b.at)[0] || null : null;
    const largest = offered.reduce((m, o) => (m === null || o.at > m.at ? o : m), null);

    // More than a ring size (≈2.6 mm round) under the smallest on the chart.
    const below = wantAt === 0 && rows[0].around - around > 2.6;

    return {
        label: below
            ? `smaller than ${sizeLabel(kind, rows[0].size)}`
            : want ? sizeLabel(kind, want.size) : `larger than ${sizeLabel(kind, rows[rows.length - 1].size)}`,
        fit,
        off: !!fit && fit.at !== wantAt,
        // More than a whole ring size (about 2.6 mm round, plus tape slack)
        // bigger than the finger: it will spin on it.
        loose: kind === 'ring' && !!fit && rows[fit.at].around - around > 3.1,
        tooSmall: !fit && largest ? largest.value : null,
    };
}

/**
 * Whether a typed length is a believable finger or hand measurement in mm.
 * A length typed in centimetres (5.4 for 54 mm) used to come out as
 * "US -12"; a slip of a digit as US 40.
 */
export function measureProblem(kind, value) {
    const v = parseFloat(value);
    if (!(v > 0)) return null;
    const [min, max] = kind === 'bangle' ? [130, 280] : [38, 80];
    if (v * 10 >= min && v * 10 <= max) return `That looks like centimetres — type it in millimetres (${Math.round(v * 10)}).`;
    if (v < min || v > max) return `That seems ${v < min ? 'small' : 'large'} for ${kind === 'bangle' ? 'a hand' : 'a finger'} — measure again, in millimetres (${min}–${max}).`;
    return null;
}

export default function SizeFinder({ guide, purchase, onClose }) {
    const kind = guide.kind;
    const rows = useMemo(() => chartRows(guide.chart), [guide.chart]);
    const attr = (purchase.attributesList || []).find((a) => a.name === guide.attribute);
    const options = useMemo(
        () => (attr?.values || []).map((v) => ({ value: v, num: numberIn(v), inStock: purchase.valueInStock(attr.name, v) })),
        [attr, purchase],
    );

    // Measuring with a tape comes first: it needs nothing but a strip of paper,
    // where the on-screen sizer needs a card and a careful hand.
    const [tab, setTab] = useState('measure');
    const [pxPerMm, setPxPerMm] = useState(() => readPxPerMm());
    const [calibrating, setCalibrating] = useState(() => readPxPerMm() === null);
    const [draftPx, setDraftPx] = useState(() => readPxPerMm() || DEFAULT_PX_PER_MM);
    const [diameter, setDiameter] = useState(() => {
        const seven = kind === 'ring' ? rows.find((r) => sameSize(kind, r.num, '7')) : null;
        return (seven || rows[Math.floor(rows.length / 2)] || { mm: kind === 'bangle' ? 60.3 : 17.3 }).mm;
    });
    const [measured, setMeasured] = useState('');
    const [source, setSource] = useState(null); // 'screen' | 'measure' | 'chart'
    const [chartPick, setChartPick] = useState(null);

    const panelRef = useRef(null);
    useEffect(() => {
        const prev = document.activeElement;
        panelRef.current?.focus();
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
            prev?.focus?.();
        };
    }, [onClose]);

    // The measurement the answer is read from: whichever tab was last used.
    const mm = source === 'measure'
        ? (parseFloat(measured) > 0 && !measureProblem(kind, measured) ? parseFloat(measured) / Math.PI : null)
        : source === 'chart'
            ? chartPick
            : (source === 'screen' && !calibrating ? diameter : null);
    const rec = recommend(kind, mm, options, rows);

    const pick = () => {
        if (!rec?.fit || !rec.fit.inStock) return;
        purchase.selectAttr(attr.name, rec.fit.value);
        onClose();
    };

    const noun = kind === 'bangle' ? 'bangle' : 'ring';
    const tabs = [
        ['measure', 'Measure'],
        ['screen', 'On screen'],
        ['chart', 'Size chart'],
    ];

    // Portalled to <body>: the buy box is a sticky column, and sticky makes a
    // stacking context, so from inside it the header and the sticky buy bar
    // would sit on top of this whatever its z-index.
    return createPortal(
        <div className="fixed inset-0 z-[120] flex items-end sm:items-center justify-center sm:p-4">
            <div className="absolute inset-0 bg-ink-900/50 backdrop-blur-[2px]" onClick={onClose} />
            <div
                ref={panelRef}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-labelledby="size-finder-title"
                onKeyDown={(e) => trapTab(e, panelRef.current)}
                className="pop-in relative w-full sm:max-w-lg max-h-[92dvh] overflow-y-auto overflow-x-hidden overscroll-contain rounded-t-[28px] sm:rounded-[28px] bg-white px-5 sm:px-7 pt-6 pb-5 shadow-2xl outline-none"
            >
                <button type="button" onClick={onClose} aria-label="Close" className="absolute right-3 top-3 grid h-10 w-10 place-items-center rounded-full hover:bg-ink-900/[0.06]">
                    <Icon name="close" className="w-4 h-4" />
                </button>

                <p className="eyebrow">Size finder</p>
                <h2 id="size-finder-title" className="display text-[1.9rem] leading-tight mt-1 pr-10">Find your {noun} size</h2>

                <div role="tablist" aria-label="How to measure" className="mt-4 grid grid-cols-3 gap-1 rounded-full bg-mist-50 p-1 border border-ink-900/[0.06]">
                    {tabs.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => setTab(key)}
                            className={`min-h-10 rounded-full text-[13px] font-medium transition-colors duration-300 ${tab === key ? 'bg-ink-900 text-white' : 'text-ink-800 hover:bg-white'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <div className="mt-5 min-h-[260px]">
                    {tab === 'screen' && (calibrating ? (
                        <Calibrate
                            px={draftPx}
                            setPx={setDraftPx}
                            onDone={() => { savePxPerMm(draftPx); setPxPerMm(draftPx); setCalibrating(false); setSource('screen'); }}
                        />
                    ) : (
                        <OnScreen
                            kind={kind}
                            rows={rows}
                            pxPerMm={pxPerMm}
                            diameter={diameter}
                            setDiameter={(d) => { setDiameter(d); setSource('screen'); }}
                            onRecalibrate={() => { setDraftPx(pxPerMm); setCalibrating(true); }}
                        />
                    ))}

                    {tab === 'measure' && (
                        <Measure kind={kind} value={measured} setValue={(v) => { setMeasured(v); setSource('measure'); }} />
                    )}

                    {tab === 'chart' && (
                        <Chart
                            kind={kind}
                            rows={rows}
                            options={options}
                            picked={source === 'chart' ? chartPick : null}
                            onPick={(d) => { setChartPick(d); setSource('chart'); }}
                        />
                    )}
                </div>

                <Answer rec={rec} attrName={attr?.name} onPick={pick} />
            </div>
        </div>,
        document.body,
    );
}

/* ── Calibrate: one bank card makes the screen a ruler ─────────────────────── */
function Calibrate({ px, setPx, onDone }) {
    const width = CARD_SHORT_MM * px;

    return (
        <div>
            <p className="text-sm leading-relaxed text-ink-700/80">
                <strong className="font-semibold text-ink-900">First, a one-time check of your screen.</strong>{' '}
                Hold any bank card or NID card upright against the screen, short edge on the line, and slide until the box is exactly as wide as the card.
            </p>
            <div className="mt-5 flex justify-center overflow-hidden">
                <div
                    className="relative h-28 rounded-t-[10px] border-2 border-b-0 border-ink-900 bg-gold-50/70"
                    style={{ width: `${width}px` }}
                    aria-hidden="true"
                >
                    <span className="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-[11px] font-medium uppercase tracking-[0.12em] text-ink-700/70">Card here</span>
                </div>
            </div>
            <div className="h-px bg-ink-900" aria-hidden="true" />

            <div className="mt-5 flex items-center gap-3">
                <StepButton label="Narrower" onClick={() => setPx(Math.max(2, +(px - 0.02).toFixed(3)))}>−</StepButton>
                <input
                    type="range"
                    min="2"
                    max="9"
                    step="0.01"
                    value={px}
                    onChange={(e) => setPx(parseFloat(e.target.value))}
                    aria-label="Box width"
                    className="flex-1 accent-ink-900 touch-none"
                />
                <StepButton label="Wider" onClick={() => setPx(Math.min(9, +(px + 0.02).toFixed(3)))}>+</StepButton>
            </div>
            <button type="button" onClick={onDone} className="btn-primary w-full mt-5 !min-h-12">It matches my card</button>
            <p className="mt-2 text-center text-xs text-ink-700/60">Remembered on this device, so you only do it once.</p>
        </div>
    );
}

/* ── On screen: lay the ring (or bangle) on the circle ─────────────────────── */
function OnScreen({ kind, rows, pxPerMm, diameter, setDiameter, onRecalibrate }) {
    const bangle = kind === 'bangle';
    // The slider spans the chart, a little either side.
    const first = rows[0]?.mm ?? (bangle ? 54 : 15);
    const last = rows[rows.length - 1]?.mm ?? (bangle ? 73 : 22);
    const [min, max, step] = bangle
        ? [Math.floor(first - 3), Math.ceil(last + 3), 0.1]
        : [Math.floor(first - 1), Math.ceil(last + 1), 0.05];
    const px = diameter * pxPerMm;
    const boxRef = useRef(null);
    const [room, setRoom] = useState(Infinity);

    useEffect(() => {
        const measure = () => setRoom(boxRef.current?.clientWidth || Infinity);
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, []);

    // A 2.8 bangle is 63.5 mm across — wider than most phones held upright.
    const fits = max * pxPerMm <= room;

    return (
        <div ref={boxRef}>
            {fits ? (
                <>
                    <p className="text-sm leading-relaxed text-ink-700/80">
                        Lay a {bangle ? 'bangle' : 'ring'} that fits you on the circle. Size the circle until its edge just meets the <em>inside</em> of the {bangle ? 'bangle' : 'ring'}.
                    </p>
                    <div className="mt-4 grid place-items-center" style={{ height: `${max * pxPerMm + 8}px` }}>
                        <div
                            className="rounded-full border-[1.5px] border-ink-900 bg-gold-50/60 shadow-[inset_0_0_0_6px_rgba(255,255,255,0.7)]"
                            style={{ width: `${px}px`, height: `${px}px` }}
                            aria-hidden="true"
                        />
                    </div>
                    <div className="mt-4 flex items-center gap-3">
                        <StepButton label="Smaller" onClick={() => setDiameter(Math.max(min, +(diameter - step * 2).toFixed(2)))}>−</StepButton>
                        <input
                            type="range"
                            min={min}
                            max={max}
                            step={step}
                            value={diameter}
                            onChange={(e) => setDiameter(parseFloat(e.target.value))}
                            aria-label="Circle size"
                            aria-valuetext={`${fmt(diameter)} millimetres`}
                            className="flex-1 accent-ink-900 touch-none"
                        />
                        <StepButton label="Bigger" onClick={() => setDiameter(Math.min(max, +(diameter + step * 2).toFixed(2)))}>+</StepButton>
                    </div>
                    <p className="mt-2 text-center text-xs tabular-nums text-ink-700/70">{mm1(diameter)} mm across the inside</p>
                </>
            ) : (
                <p className="rounded-2xl bg-mist-50 border border-ink-900/[0.06] p-4 text-sm leading-relaxed text-ink-800">
                    A bangle is wider than this screen. Use <strong className="font-semibold">Measure</strong> instead — it only takes a thread — or try on a tablet or laptop.
                </p>
            )}
            <button type="button" onClick={onRecalibrate} className="mt-3 block mx-auto text-xs text-ink-700/70 hover:text-ink-900 lux-link">Check my screen again</button>
        </div>
    );
}

/* ── Measure: thread or paper strip, in millimetres ────────────────────────── */
function Measure({ kind, value, setValue }) {
    const bangle = kind === 'bangle';

    return (
        <div>
            <figure className="mb-4 overflow-hidden rounded-2xl bg-mist-50">
                <img
                    src={bangle ? '/images/size-guide/measure-bangle.webp' : '/images/size-guide/measure-ring.webp'}
                    width="840"
                    height="633"
                    alt={bangle
                        ? 'A measuring tape wrapped round the widest part of a hand, over the knuckles, with the thumb tucked in'
                        : 'A measuring tape wrapped round the base of the ring finger, read where the end overlaps'}
                    className="block w-full aspect-[4/3] object-cover"
                    decoding="async"
                />
            </figure>
            <ol className="grid gap-2.5 text-sm leading-relaxed text-ink-800">
                {(bangle
                    ? [
                        'Bring your thumb in to touch your little finger, the way you slip a bangle on.',
                        'Wrap a measuring tape (or a thread or paper strip) round the widest part of your hand, over the knuckles.',
                        'Read the number where the end overlaps (a thread: mark it and lay it on a ruler) and type it below.',
                    ]
                    : [
                        'Wrap a measuring tape (or a thin strip of paper) round the base of the finger.',
                        'Snug, but it should still slide over the knuckle.',
                        'Read the number where the end overlaps (paper: mark it and lay it on a ruler) and type it below.',
                    ]).map((s, i) => (
                    <li key={i} className="flex gap-3">
                        <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-900 text-[11px] font-semibold text-white">{i + 1}</span>
                        <span className="pt-0.5">{s}</span>
                    </li>
                ))}
            </ol>
            <label className="mt-5 block">
                <span className="label">Round your {bangle ? 'hand' : 'finger'}, in millimetres</span>
                <span className="relative block">
                    <input
                        type="number"
                        inputMode="decimal"
                        min="0"
                        step="0.5"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        placeholder={bangle ? 'e.g. 185' : 'e.g. 54'}
                        className="input pr-14"
                    />
                    <span className="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-ink-700/60">mm</span>
                </span>
            </label>
            {measureProblem(kind, value) && (
                <p className="mt-2 text-sm font-medium text-danger-600" aria-live="polite">{measureProblem(kind, value)}</p>
            )}
            <p className="mt-3 text-xs leading-relaxed text-ink-700/60">
                {bangle
                    ? 'Measure the hand, not the wrist — a bangle has to pass over the knuckles.'
                    : 'Fingers are a little bigger in the evening and in the heat. Measure then, and if you are between two sizes, go up.'}
            </p>
        </div>
    );
}

/* ── Chart: every size, with the ones this piece comes in marked ───────────── */
function Chart({ kind, rows: chart, options, picked, onPick }) {
    // null: not made in that size; true / false: made, and in stock or not.
    const stock = (match) => {
        const hits = options.filter((o) => o.num !== null && match(o.num));
        return hits.length ? hits.some((o) => o.inStock) : null;
    };
    const rows = chart.map((r) => ({ key: r.size, label: sizeLabel(kind, r.size), mm: r.mm, around: r.around, has: stock((n) => sameSize(kind, r.num, n)) }));

    return (
        <div>
            <p className="text-sm text-ink-700/80">Know the size already? Tap it.</p>
            <table className="mt-3 w-full text-sm tabular-nums">
                <thead>
                    <tr className="text-left text-[11px] uppercase tracking-[0.1em] text-ink-700/60">
                        <th className="py-2 font-medium">Size</th>
                        <th className="py-2 font-medium">Inside ⌀</th>
                        <th className="py-2 font-medium">Around</th>
                        <th className="py-2 font-medium sr-only sm:not-sr-only">This piece</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => {
                        const active = picked !== null && Math.abs(picked - r.mm) < 0.01;
                        return (
                            <tr key={r.key} className={`border-t border-ink-900/[0.06] ${active ? 'bg-gold-50' : ''}`}>
                                <td className="py-0">
                                    <button type="button" onClick={() => onPick(r.mm)} className="w-full min-h-10 text-left font-medium text-ink-900 hover:underline underline-offset-4">
                                        {r.label}
                                    </button>
                                </td>
                                <td className="text-ink-700/80">{mm1(r.mm)} mm</td>
                                <td className="text-ink-700/80">{mm1(r.around)} mm</td>
                                <td className="text-xs">
                                    {r.has === true && <span className="inline-flex items-center gap-1 text-success-700"><Icon name="check" className="w-3 h-3" strokeWidth={3} />Available</span>}
                                    {r.has === false && <span className="text-danger-600">Sold out</span>}
                                    {r.has === null && <span className="text-ink-700/40">—</span>}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/* ── The answer, and the button that picks it on the page ──────────────────── */
// The same height whatever it says. It used to grow and shrink as the slider
// crossed sizes (a line appears, the button goes), and on a phone the sheet is
// pinned to the bottom, so its whole top edge jumped under the finger.
const ANSWER_BOX = 'mt-5 min-h-[10.5rem] rounded-2xl border px-4 py-4';
function Answer({ rec, attrName, onPick }) {
    if (!rec) {
        return (
            <div className={`${ANSWER_BOX} grid place-items-center bg-mist-50 border-ink-900/[0.06] text-sm text-ink-700/70`} aria-live="polite">
                Your size will show here.
            </div>
        );
    }

    const fit = rec.fit;

    return (
        <div className={`${ANSWER_BOX} border-ink-900 bg-white`} aria-live="polite">
            <p className="text-sm text-ink-700/80">
                Your size is <strong className="text-lg font-semibold text-ink-900">{rec.label}</strong>
            </p>
            {fit && (rec.off || rec.loose) && (
                <p className="mt-1 text-xs text-ink-700/70">
                    The closest this piece comes in is <strong className="font-semibold text-ink-900">{fit.value}</strong>
                    {rec.loose ? ' — it will be loose on you.' : '.'}
                </p>
            )}
            {!fit && (
                <p className="mt-1 text-xs text-ink-700/70">
                    {rec.tooSmall
                        ? <>This piece only goes up to <strong className="font-semibold text-ink-900">{rec.tooSmall}</strong>, which would be too tight.</>
                        : 'This piece does not come in that size.'}
                </p>
            )}
            {fit && (fit.inStock ? (
                <button type="button" onClick={onPick} className="btn-primary w-full mt-3 !min-h-12">
                    Choose {attrName ? `${attrName.toLowerCase()} ` : ''}{fit.value}
                </button>
            ) : (
                <p className="mt-2 text-sm font-medium text-danger-600">{fit.value} is sold out in this piece right now.</p>
            ))}
        </div>
    );
}

function StepButton({ label, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-ink-900/15 text-lg text-ink-900 hover:bg-ink-900/[0.05]"
        >
            {children}
        </button>
    );
}
