import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon from '../Icons';
import { csrf } from '../format';

// Notification bell for logged-in customers, with the web-push opt-in nudge.
// Push plumbing (window.wpEnsure) is provided by partials/web-push.blade.php.
export default function NotificationsBell() {
    const { props } = usePage();
    const data = props.chrome?.notifications;
    const urls = props.chrome?.urls || {};
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(data?.unread ?? 0);
    const [pushState, setPushState] = useState('idle');
    const [pushBusy, setPushBusy] = useState(false);
    const ref = useRef(null);

    useEffect(() => setUnread(data?.unread ?? 0), [data?.unread]);

    useEffect(() => {
        const close = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('click', close);
        return () => document.removeEventListener('click', close);
    }, []);

    if (!data) return null;

    const pushSupported = typeof window !== 'undefined' && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
    const showOptIn = data.webPushReady && pushSupported && pushState !== 'granted'
        && (typeof Notification === 'undefined' || Notification.permission !== 'granted');

    const toggle = () => {
        const next = !open;
        setOpen(next);
        if (next && unread > 0 && urls.notificationsRead) {
            fetch(urls.notificationsRead, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            });
            setUnread(0);
        }
    };

    const enablePush = async () => {
        if (pushBusy || !window.wpEnsure) return;
        setPushBusy(true);
        try {
            const sub = await window.wpEnsure();
            setPushState(sub ? 'granted' : 'denied');
        } finally {
            setPushBusy(false);
        }
    };

    return (
        <div className="relative" ref={ref}>
            <button type="button" onClick={toggle} className="relative p-2 hover:text-gold-700" title="Notifications" aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'} aria-expanded={open}>
                <Icon name="bell" />
                {unread > 0 && (
                    <span aria-hidden="true" className="absolute top-0.5 right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger-600 text-white text-[10px] font-semibold flex items-center justify-center">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>
            {open && (
                <div className="absolute right-0 mt-2 w-80 max-h-[70vh] overflow-y-auto rounded-xl border border-ink-100 bg-white shadow-2xl z-50 p-2">
                    <div className="flex items-center justify-between px-2 py-1.5">
                        <p className="text-sm font-semibold">Notifications</p>
                        <a href={urls.accountNotifications} className="text-xs text-gold-700 hover:underline">See all</a>
                    </div>
                    {showOptIn && (
                        <div className="mx-1 mb-1 rounded-lg bg-gold-50 border border-gold-200 px-2.5 py-2 flex items-center gap-2">
                            <Icon name="bell" className="w-5 h-5" />
                            <p className="text-xs flex-1 text-ink-700/70">Get notified about new drops &amp; your offers — even when the site is closed.</p>
                            <button
                                type="button"
                                onClick={enablePush}
                                disabled={pushBusy}
                                className="text-xs font-semibold text-white bg-gold-600 rounded-md px-2 py-1 hover:bg-gold-700 disabled:opacity-50"
                            >
                                {pushBusy ? '…' : 'Turn on'}
                            </button>
                        </div>
                    )}
                    {data.items.length ? data.items.map((n, i) => (
                        <a key={i} href={n.url} className="block px-2 py-2 rounded-lg hover:bg-ink-50">
                            <p className="text-sm font-medium">{n.icon} {n.title}</p>
                            {n.body && <p className="text-xs text-ink-700/70 line-clamp-2">{n.body}</p>}
                            <p className="text-[11px] text-ink-700/70 mt-0.5">{n.time}</p>
                        </a>
                    )) : (
                        <p className="px-2 py-6 text-sm text-ink-700/70 text-center">No notifications yet.</p>
                    )}
                </div>
            )}
        </div>
    );
}
