import { usePage } from '@inertiajs/react';
import Icon from '../Icons';
import { useCart } from '../CartContext';
import SmartLink from '../SmartLink';

// Mobile bottom navigation: Home · Discover · Cart · Profile.
export default function BottomNav() {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const { count, openDrawer } = useCart();

    const item = 'flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium';

    return (
        <>
            <div className="md:hidden" style={{ height: 'calc(3.5rem + env(safe-area-inset-bottom))' }} />
            <nav
                className="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur border-t border-ink-100 shadow-[0_-2px_12px_rgba(0,0,0,0.06)]"
                style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            >
                <div className="grid grid-cols-4">
                    <SmartLink href={urls.home || '/'} className={`${item} text-ink-700/70`}>
                        <Icon name="home" className="w-6 h-6" strokeWidth={1.6} />
                        Home
                    </SmartLink>
                    <a href={urls.discover} className={`${item} text-ink-700/70`}>
                        <Icon name="discover" className="w-6 h-6" strokeWidth={1.6} />
                        Discover
                    </a>
                    <button type="button" onClick={openDrawer} className={`relative ${item} text-ink-700/70`}>
                        <span className="relative">
                            <Icon name="cart" className="w-6 h-6" strokeWidth={1.6} />
                            {count > 0 && (
                                <span className="absolute -top-1.5 -right-2 badge bg-gold-600 text-white px-1.5 min-w-[18px] justify-center text-[10px]">{count}</span>
                            )}
                        </span>
                        Cart
                    </button>
                    <a href={props.customer ? urls.account : urls.login} className={`${item} text-ink-700/70`}>
                        <Icon name="userFull" className="w-6 h-6" strokeWidth={1.6} />
                        {props.customer ? 'Profile' : 'Sign in'}
                    </a>
                </div>
            </nav>
        </>
    );
}
