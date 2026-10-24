import { usePage } from '@inertiajs/react';
import Icon from '../Icons';
import { useCart } from '../CartContext';
import SmartLink from '../SmartLink';

// Mobile bottom navigation: Home · Discover · Cart · Profile.
export default function BottomNav() {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const { count, openDrawer } = useCart();

    const item = 'flex flex-col items-center gap-1 pt-2 pb-1.5 text-[10.5px] font-medium tracking-[0.02em]';

    return (
        <>
            <div className="md:hidden" style={{ height: 'calc(3.5rem + env(safe-area-inset-bottom))' }} />
            <nav
                aria-label="Quick links"
                className="md:hidden fixed bottom-0 inset-x-0 z-40 glass border-t border-ink-900/[0.06]"
                style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            >
                <div className="grid grid-cols-4">
                    <SmartLink href={urls.home || '/'} className={`${item} text-ink-800`}>
                        <Icon name="home" className="w-[22px] h-[22px]" strokeWidth={1.5} />
                        Home
                    </SmartLink>
                    <a href={urls.discover} className={`${item} text-ink-800`}>
                        <Icon name="discover" className="w-[22px] h-[22px]" strokeWidth={1.5} />
                        Discover
                    </a>
                    <button type="button" onClick={openDrawer} className={`relative ${item} text-ink-800`}>
                        <span className="relative">
                            <Icon name="bag" className="w-[22px] h-[22px]" strokeWidth={1.5} />
                            {count > 0 && (
                                <span className="absolute -top-1.5 -right-2 min-w-[18px] h-[18px] px-1 rounded-full bg-ink-900 text-white text-[10px] font-semibold grid place-items-center ring-2 ring-white">{count}</span>
                            )}
                        </span>
                        Cart
                    </button>
                    <a href={props.customer ? urls.account : urls.login} className={`${item} text-ink-800`}>
                        <Icon name="userFull" className="w-[22px] h-[22px]" strokeWidth={1.5} />
                        {props.customer ? 'Profile' : 'Sign in'}
                    </a>
                </div>
            </nav>
        </>
    );
}
