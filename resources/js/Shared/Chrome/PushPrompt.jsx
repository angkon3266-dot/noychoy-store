import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';

// Web-push opt-in, asked once per trigger.
//
// partials/web-push.blade.php only runs on a full page load, so a register or a
// checkout done over Inertia never reached it — which is the real reason the
// post-registration prompt could never fire, not the localStorage key the
// roadmap blamed. The trigger now arrives as a shared prop instead.
//
// One key PER trigger: a single shared key let whichever trigger fired first
// silence the other permanently, including when the visitor dismissed the
// browser prompt without deciding (permission stays 'default').
export default function PushPrompt() {
    const trigger = usePage().props.pushPrompt;
    const fired = useRef(false);

    useEffect(() => {
        if (!trigger || fired.current) return;
        fired.current = true;

        const key = `wp_asked_${trigger}`;
        if (localStorage.getItem(key)) return;

        // Nothing to ask if the browser has already decided, or if push is off
        // — wpEnsure simply does not exist when the service is not ready.
        if (typeof Notification === 'undefined' || Notification.permission !== 'default') return;
        if (!window.wpEnsure) return;

        localStorage.setItem(key, '1');
        window.wpEnsure().catch(() => {});
    }, [trigger]);

    return null;
}
