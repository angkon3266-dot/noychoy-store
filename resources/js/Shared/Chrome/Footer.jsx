import { usePage } from '@inertiajs/react';
import Icon, { IconOrGlyph, Messenger, SOCIAL_ICONS, WhatsApp } from '../Icons';
import SmartLink from '../SmartLink';
import { t } from '../i18n';

// `minimal` strips the footer back to the copyright line. Checkout uses it:
// once someone is filling in their address, a full site menu under the form is
// nothing but a set of ways to leave before the order is placed.
export default function Footer({ minimal = false }) {
    const { props } = usePage();
    const chrome = props.chrome || {};
    const footer = chrome.footer || {};
    const urls = chrome.urls || {};
    const lang = chrome.lang || 'en';
    // The English / বাংলা switch is gone from the footer (owner, 2026-10-24):
    // it translated a handful of labels and read as doing nothing.

    if (minimal) {
        return (
            <footer className="mt-16 border-t border-ink-900/[0.07]">
                <div className="mx-auto max-w-[1440px] px-4 py-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-ink-700/60">
                    <span className="inline-flex items-center gap-1.5"><Icon name="shieldCheck" className="w-4 h-4" strokeWidth={1.5} /> Secure checkout · Cash on delivery</span>
                    <span>{footer.copyright}</span>
                </div>
            </footer>
        );
    }

    const heading = 'font-sans text-[11px] font-medium uppercase tracking-[0.24em] text-white/45';
    const link = 'text-[14px] text-white/75 hover:text-white transition-colors';
    const wordmark = (chrome.storeName || footer.brand || '').trim();

    return (
        <footer className="mt-20 lg:mt-28 px-2 sm:px-3 pb-2 sm:pb-3">
            <div className="grain relative overflow-hidden rounded-[28px] lg:rounded-[40px] bg-ink-900 text-white">
                <div className="pointer-events-none absolute -top-56 left-1/3 w-[700px] h-[700px] rounded-full opacity-25 blur-3xl" style={{ background: 'radial-gradient(circle, var(--color-gold-500), transparent 60%)' }} aria-hidden="true" />

                {footer.showTrust && footer.trustBadges?.length > 0 && (
                    <div className="relative border-b border-white/10">
                        <div className="mx-auto max-w-[1440px] px-6 lg:px-12 grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-white/10">
                            {footer.trustBadges.map((b, i) => (
                                <div key={i} className="flex items-center gap-4 py-6 sm:py-8 sm:px-6 first:sm:pl-0">
                                    <span className="grid place-items-center w-11 h-11 shrink-0 rounded-full border border-white/15 text-gold-400">
                                        <IconOrGlyph value={b.icon} fallback="check" className="w-5 h-5" />
                                    </span>
                                    <div>
                                        <div className="text-[14px] font-medium">{b.title}</div>
                                        {b.text && <div className="text-[12px] text-white/50 mt-0.5">{b.text}</div>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="relative mx-auto max-w-[1440px] px-6 lg:px-12 pt-14 lg:pt-20 pb-10 grid gap-12 lg:grid-cols-12">
                    <div className="lg:col-span-4">
                        <p className="font-display text-3xl lg:text-4xl tracking-[-0.02em]">{footer.brand}</p>
                        {footer.about && <p className="mt-4 text-[14px] leading-relaxed text-white/60 max-w-sm">{footer.about}</p>}
                        {footer.socials?.length > 0 && (
                            <div className="mt-7 flex gap-2.5">
                                {footer.socials.map(({ platform, label, url }) => {
                                    const Glyph = SOCIAL_ICONS[platform];

                                    return Glyph && (
                                        <a key={platform} href={url} target="_blank" rel="noopener" aria-label={label} className="grid place-items-center w-11 h-11 rounded-full border border-white/15 text-white/80 hover:bg-white hover:text-ink-900 hover:border-white transition-colors duration-300">
                                            <Glyph />
                                        </a>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    <div className="lg:col-span-8 grid grid-cols-2 sm:grid-cols-3 gap-10">
                        <div>
                            <h2 className={heading}>Shop</h2>
                            <ul className="mt-5 space-y-3">
                                {(footer.categories || []).map((cat) => (
                                    <li key={cat.url}><SmartLink href={cat.url} className={link}>{cat.name}</SmartLink></li>
                                ))}
                                {urls.shop && <li><SmartLink href={urls.shop} className={link}>Shop all</SmartLink></li>}
                            </ul>
                        </div>
                        <div>
                            <h2 className={heading}>Help</h2>
                            <ul className="mt-5 space-y-3">
                                <li><a href={urls.about} className={link}>Our story</a></li>
                                <li><a href={urls.track} className={link}>Track your order</a></li>
                                <li><a href={urls.contact} className={link}>Contact us</a></li>
                                <li><a href={urls.privacy} className={link}>Privacy Policy</a></li>
                                <li><a href={urls.terms} className={link}>Terms &amp; Conditions</a></li>
                                <li><a href={urls.refund} className={link}>Refund Policy</a></li>
                                {!props.customer && (
                                    <li>
                                        <a href={props.chrome?.membership?.pct ? urls.register : urls.login} className={link} lang={lang}>
                                            {props.chrome?.membership?.pct ? t(lang, 'footer.join', { pct: props.chrome.membership.pct }) : 'Login / Register'}
                                        </a>
                                    </li>
                                )}
                                {props.customer && <li><a href={urls.account} className={link} lang={lang}>{t(lang, 'footer.account')}</a></li>}
                            </ul>
                        </div>
                        <div className="col-span-2 sm:col-span-1">
                            <h2 className={heading}>Contact</h2>
                            <ul className="mt-5 space-y-3">
                                {footer.email && (
                                    <li>
                                        <a href={`mailto:${footer.email}`} className={`${link} inline-flex items-center gap-2.5`}>
                                            <Icon name="mail" className="w-4 h-4 text-white/45 shrink-0" />{footer.email}
                                        </a>
                                    </li>
                                )}
                                {footer.whatsapp && (
                                    <li>
                                        <a href={`https://wa.me/${footer.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener" className={`${link} inline-flex items-center gap-2.5`}>
                                            <WhatsApp className="w-4 h-4 text-[#25D366]" />
                                            WhatsApp
                                        </a>
                                    </li>
                                )}
                                {footer.messenger && (
                                    <li>
                                        <a href={footer.messenger} target="_blank" rel="noopener" className={`${link} inline-flex items-center gap-2.5`}>
                                            <Messenger className="w-4 h-4 text-[#0084FF]" />
                                            Messenger
                                        </a>
                                    </li>
                                )}
                            </ul>
                        </div>
                    </div>
                </div>

                {/* The signature: the name, set huge and quiet across the foot. */}
                {wordmark && (
                    <div className="relative px-4 lg:px-8 select-none" aria-hidden="true">
                        <p className="font-display text-center leading-[0.8] tracking-[-0.045em] text-white/[0.07] whitespace-nowrap overflow-hidden" style={{ fontSize: `clamp(4rem, ${Math.min(26, 150 / Math.max(4, wordmark.length))}vw, 22rem)` }}>
                            {wordmark}
                        </p>
                    </div>
                )}

                <div className="relative border-t border-white/10">
                    <div className="mx-auto max-w-[1440px] px-6 lg:px-12 py-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-[12px] text-white/45">
                        <span>{footer.copyright}</span>
                        <span className="inline-flex items-center gap-2">
                            <Icon name="cash" className="w-4 h-4" strokeWidth={1.5} />
                            Cash on delivery across Bangladesh
                        </span>
                    </div>
                </div>
            </div>
        </footer>
    );
}
