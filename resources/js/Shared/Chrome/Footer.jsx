import { usePage } from '@inertiajs/react';
import Icon, { Facebook, IconOrGlyph, Instagram, WhatsApp } from '../Icons';
import SmartLink from '../SmartLink';

export default function Footer() {
    const { props } = usePage();
    const chrome = props.chrome || {};
    const footer = chrome.footer || {};
    const urls = chrome.urls || {};

    return (
        <footer className="mt-16 bg-ink-900 text-gold-100">
            {footer.showTrust && footer.trustBadges?.length > 0 && (
                <div className="border-b border-white/10">
                    <div className="mx-auto max-w-7xl px-4 py-6 grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                        {footer.trustBadges.map((b, i) => (
                            <div key={i} className="flex items-center justify-center gap-2">
                                <IconOrGlyph value={b.icon} fallback="check" className="w-6 h-6 text-gold-300 shrink-0" />
                                <div className="text-left">
                                    <div className="text-sm font-medium text-gold-100">{b.title}</div>
                                    {b.text && <div className="text-xs text-gold-100/60">{b.text}</div>}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            <div className="mx-auto max-w-7xl px-4 py-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <div className="font-display text-xl font-bold text-gold-300">{footer.brand}</div>
                    <p className="mt-3 text-sm text-gold-100/70">{footer.about}</p>
                    {(footer.facebook || footer.instagram) && (
                        <div className="mt-4 flex gap-3">
                            {footer.facebook && <a href={footer.facebook} target="_blank" rel="noopener" className="text-gold-100/70 hover:text-white" aria-label="Facebook"><Facebook /></a>}
                            {footer.instagram && <a href={footer.instagram} target="_blank" rel="noopener" className="text-gold-100/70 hover:text-white" aria-label="Instagram"><Instagram /></a>}
                        </div>
                    )}
                </div>
                <div>
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gold-300">Shop</h2>
                    <ul className="mt-3 space-y-2 text-sm text-gold-100/80">
                        {(footer.categories || []).map((cat) => (
                            <li key={cat.url}><SmartLink href={cat.url} className="hover:text-white">{cat.name}</SmartLink></li>
                        ))}
                    </ul>
                </div>
                <div>
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gold-300">Help</h2>
                    <ul className="mt-3 space-y-2 text-sm text-gold-100/80">
                        <li><a href={urls.about} className="hover:text-white">Our story</a></li>
                        <li><a href={urls.track} className="hover:text-white">Track your order</a></li>
                        <li><a href={urls.contact} className="hover:text-white">Contact us</a></li>
                        <li><a href={urls.privacy} className="hover:text-white">Privacy Policy</a></li>
                        <li><a href={urls.terms} className="hover:text-white">Terms &amp; Conditions</a></li>
                        <li><a href={urls.refund} className="hover:text-white">Refund Policy</a></li>
                        {!props.customer && <li><a href={urls.login} className="hover:text-white">Login / Register</a></li>}
                    </ul>
                </div>
                <div>
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-gold-300">Contact</h2>
                    <ul className="mt-3 space-y-2 text-sm text-gold-100/80">
                        {footer.phone && <li className="flex items-center gap-2"><Icon name="phone" className="w-4 h-4 text-gold-300 shrink-0" />{footer.phone}</li>}
                        <li className="flex items-center gap-2"><Icon name="mail" className="w-4 h-4 text-gold-300 shrink-0" />{footer.email}</li>
                        {footer.whatsapp && (
                            <li>
                                <a href={`https://wa.me/${footer.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener" className="hover:text-white inline-flex items-center gap-1">
                                    <WhatsApp className="w-4 h-4 text-[#25D366]" />
                                    WhatsApp
                                </a>
                            </li>
                        )}
                    </ul>
                </div>
            </div>
            <div className="border-t border-white/10 py-4 text-center text-xs text-gold-100/50">
                {footer.copyright}
            </div>
        </footer>
    );
}
