import Layout from '../Shared/Chrome/Layout';
import SmartLink from '../Shared/SmartLink';
import { IconOrGlyph } from '../Shared/Icons';

/**
 * The brand story page (/about).
 *
 * Built from the same pieces as the rest of the storefront — gold eyebrow,
 * display heading, cream/white alternation — rather than the bare `prose`
 * column it used to be. The promise strip reads the *same* trust badges the
 * footer and product pages read, so this page cannot drift into promising
 * something the rest of the site does not.
 *
 * `body` is admin-authored HTML, the same trust boundary as Blade's {!! !!}
 * and identical to how the legal pages have always rendered it.
 */
export default function Story({ title, eyebrow, headline, lede, heroImage, body, promises = [], shopUrl, contactUrl }) {
    return (
        <div>
            {/* Hero. With an image it is a full-bleed photo with a scrim; without
                one it is a cream band — which still reads as designed, rather
                than as a picture that failed to load. */}
            <section className={`relative overflow-hidden ${heroImage ? '' : 'bg-gold-50'}`}>
                {heroImage && (
                    <>
                        <img
                            src={heroImage}
                            alt=""
                            className="absolute inset-0 h-full w-full object-cover"
                            fetchPriority="high"
                        />
                        <div className="absolute inset-0 bg-gradient-to-b from-white/70 via-white/55 to-white/70"></div>
                    </>
                )}
                <div className="relative mx-auto max-w-3xl px-4 py-20 sm:py-28 text-center">
                    {eyebrow && (
                        <p className="uppercase tracking-[0.35em] text-xs text-gold-700 mb-4">{eyebrow}</p>
                    )}
                    <h1 className="font-display text-4xl sm:text-6xl font-semibold leading-tight text-ink-900">
                        {headline || title}
                    </h1>
                    {lede && (
                        <p className="mt-5 text-base sm:text-lg text-ink-700/80 leading-relaxed max-w-2xl mx-auto">
                            {lede}
                        </p>
                    )}
                </div>
            </section>

            {/* The story itself. White against the cream header and the cream
                promise strip below it — the page reads as bands rather than one
                undifferentiated field. */}
            <section className="bg-white">
                <div className="mx-auto max-w-3xl px-4 py-14 sm:py-20">
                    <div className="rich-text" dangerouslySetInnerHTML={{ __html: body }} />
                </div>
            </section>

            {/* What the shop promises — the footer's trust badges, verbatim. */}
            {promises.length > 0 && (
                <section className="bg-gold-50/60 py-14 sm:py-16">
                    <div className="mx-auto max-w-6xl px-4">
                        <div className="text-center mb-10">
                            <p className="uppercase tracking-[0.3em] text-xs text-gold-700 mb-3">Our promise</p>
                            <h2 className="font-display text-3xl sm:text-4xl text-ink-900">
                                What you can count on
                                <span className="block h-0.5 w-16 bg-gold-500 mt-3 mx-auto"></span>
                            </h2>
                        </div>
                        <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                            {promises.map((p, i) => (
                                <div key={i} className="text-center">
                                    <span className="inline-grid h-12 w-12 place-items-center rounded-full bg-white text-gold-700 shadow-sm mb-3">
                                        <IconOrGlyph value={p.icon} className="w-6 h-6" />
                                    </span>
                                    <h3 className="font-medium text-ink-900">{p.title}</h3>
                                    {p.text && <p className="mt-1 text-sm text-ink-700/70">{p.text}</p>}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* Closing move: the page exists to end in the catalogue. */}
            <section className="mx-auto max-w-3xl px-4 py-14 sm:py-16 text-center">
                <h2 className="font-display text-2xl sm:text-3xl text-ink-900">Have a look around</h2>
                <p className="mt-3 text-ink-700/75">
                    Every piece is photographed as it actually arrives, with the price on the page.
                </p>
                <div className="mt-7 flex flex-wrap items-center justify-center gap-3">
                    <SmartLink href={shopUrl} className="btn-dark">Shop the collection</SmartLink>
                    <SmartLink href={contactUrl} className="btn-outline">Talk to us</SmartLink>
                </div>
            </section>
        </div>
    );
}

Story.layout = (page) => <Layout>{page}</Layout>;
