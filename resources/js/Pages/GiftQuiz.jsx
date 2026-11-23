import { useEffect, useMemo, useState } from 'react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import SmartLink from '../Shared/SmartLink';
import Icon from '../Shared/Icons';
import { fetchJson } from '../Shared/format';
import { MoreTools, shell } from '../Shared/StyleKit';

/*
 * The gift quiz (Nov 2026): five taps — who, what for, what she wears, her
 * style, the budget — then a shortlist ranked on the server
 * (StyleController::giftQuizResults). The answers are remembered the way the
 * homepage finder's are (GiftProfile), so "Picked for you" follows them.
 */
export default function GiftQuiz({ recipients, occasions, types, styles, budgets, profile, resultsUrl }) {
    const steps = useMemo(() => [
        { key: 'for', q: 'Who is it for?', options: recipients },
        { key: 'occasion', q: 'What’s the occasion?', options: occasions },
        { key: 'type', q: 'What do they wear most?', options: types },
        { key: 'style', q: 'And their style?', options: styles },
        budgets.length ? { key: 'budget', q: 'What would you like to spend?', options: budgets.map((b, i) => [String(i), b.label]) } : null,
    ].filter(Boolean), [recipients, occasions, types, styles, budgets]);

    const [answers, setAnswers] = useState(() => ({
        for: profile?.for || null,
        occasion: profile?.occasion || null,
    }));
    const [step, setStep] = useState(0);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);

    const done = step >= steps.length;
    const current = steps[step];

    const choose = (key, value) => {
        setAnswers((a) => ({ ...a, [key]: value }));
        setStep((s) => s + 1);
    };

    useEffect(() => {
        if (!done) return;
        const budget = answers.budget != null ? budgets[Number(answers.budget)] : null;
        const q = new URLSearchParams();
        ['for', 'occasion', 'type', 'style'].forEach((k) => answers[k] && q.set(k, answers[k]));
        if (budget?.min != null) q.set('price_min', budget.min);
        if (budget?.max != null) q.set('price_max', budget.max);
        setLoading(true);
        setError(false);
        fetchJson(`${resultsUrl}?${q.toString()}`)
            .then(setResult)
            .catch(() => setError(true))
            .finally(() => setLoading(false));
    }, [done]); // eslint-disable-line react-hooks/exhaustive-deps

    const restart = () => { setStep(0); setResult(null); };
    const labelOf = (key) => {
        const s = steps.find((x) => x.key === key);
        return s?.options.find(([k]) => String(k) === String(answers[key]))?.[1];
    };

    return (
        <div className={`${shell} pb-10`}>
            <div className="pt-8 lg:pt-14">
                <div className="relative overflow-hidden rounded-[32px] bg-ink-900 text-white px-6 py-10 sm:px-10 lg:px-16 lg:py-16">
                    <div className="pointer-events-none absolute -top-48 -right-40 w-[560px] h-[560px] rounded-full opacity-50 blur-3xl" style={{ background: 'radial-gradient(circle, var(--color-gold-500), transparent 62%)' }} aria-hidden="true" />
                    <div className="relative">
                        <p className="eyebrow !text-white/60 flex items-center gap-3"><span className="h-px w-8 bg-white/40" aria-hidden="true" />Gift finder</p>

                        {!done ? (
                            <>
                                <div className="mt-5 flex gap-1.5" aria-hidden="true">
                                    {steps.map((s, i) => (
                                        <span key={s.key} className={`h-1 flex-1 max-w-16 rounded-full transition-colors duration-500 ${i <= step ? 'bg-gold-400' : 'bg-white/15'}`} />
                                    ))}
                                </div>
                                <p className="mt-6 text-[12px] tabular-nums text-white/50">Question {step + 1} of {steps.length}</p>
                                <h1 className="display !text-white mt-2 text-[2.2rem] sm:text-5xl lg:text-6xl" aria-live="polite">{current.q}</h1>
                                <div key={current.key} className="pop-in mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 max-w-4xl">
                                    {current.options.map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => choose(current.key, value)}
                                            className={`group flex items-center justify-between gap-4 rounded-2xl border px-5 py-4 text-left text-[15px] transition-colors duration-300 ${String(answers[current.key]) === String(value) ? 'border-white bg-white/10' : 'border-white/15 hover:bg-white hover:text-ink-900 hover:border-white'}`}
                                        >
                                            <span className="font-medium">{label}</span>
                                            <span aria-hidden="true" className="opacity-50 transition-transform duration-300 group-hover:translate-x-1">→</span>
                                        </button>
                                    ))}
                                </div>
                                <div className="mt-8 flex items-center gap-5 text-sm">
                                    {step > 0 && <button type="button" onClick={() => setStep((s) => s - 1)} className="text-white/70 hover:text-white">← Back</button>}
                                    <button type="button" onClick={() => choose(current.key, null)} className="text-white/50 hover:text-white">Skip this one</button>
                                </div>
                            </>
                        ) : (
                            <>
                                <h1 className="display !text-white mt-4 text-[2.2rem] sm:text-5xl lg:text-6xl">Your shortlist</h1>
                                <p className="mt-3 text-sm text-white/65">
                                    {[labelOf('for'), labelOf('occasion'), labelOf('type'), labelOf('style'), labelOf('budget')].filter(Boolean).join(' · ') || 'Our most-loved pieces'}
                                </p>
                                <button type="button" onClick={restart} className="mt-5 inline-flex items-center gap-2 text-sm text-white/80 hover:text-white lux-link">Start again</button>
                            </>
                        )}
                    </div>
                </div>
            </div>

            {done && (
                <section className="mt-10" aria-live="polite">
                    {loading && <p className="text-sm text-ink-700/70">Finding the right pieces…</p>}
                    {error && <p className="text-sm text-danger-600">Something went wrong — <button type="button" onClick={restart} className="underline">try again</button>.</p>}
                    {result && (
                        <>
                            {result.relaxed && (
                                <p className="mb-6 flex items-start gap-2 text-sm text-ink-800">
                                    <Icon name="bulb" className="w-4 h-4 mt-0.5 shrink-0 text-gold-600" />
                                    Only a few of those in that budget, so we have added other pieces we think they would love.
                                </p>
                            )}
                            {result.cards.length === 0 ? (
                                <p className="text-ink-800">Nothing in that budget right now. <button type="button" onClick={() => setStep(steps.length - 1)} className="underline underline-offset-4">Try another budget</button>.</p>
                            ) : (
                                <div className="grid grid-cols-2 md:grid-cols-3 gap-x-3 sm:gap-x-5 gap-y-10">
                                    {result.cards.map((p) => <ProductCard key={p.id} product={p} />)}
                                </div>
                            )}
                            {result.total > result.cards.length && (
                                <div className="mt-10 text-center">
                                    <SmartLink href={result.more_url} className="btn-outline inline-flex">See all {result.total} that fit</SmartLink>
                                </div>
                            )}
                            <p className="mt-8 text-center text-xs text-ink-700/60">Every piece can go gift-ready, with no price slip and a card message — just tick “This is a gift” at checkout.</p>
                        </>
                    )}
                </section>
            )}

            <MoreTools current="giftQuiz" />
        </div>
    );
}

GiftQuiz.layout = (page) => <Layout>{page}</Layout>;
