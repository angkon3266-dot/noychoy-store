import { useEffect, useState } from 'react';

// Live countdown against a unix deadline — port of Alpine countdownBox. The
// page can be served long after render (caches), so the deadline is always
// re-checked in the browser.
export default function useCountdown(endsAtUnix) {
    const calc = () => {
        const left = Math.max(0, (endsAtUnix || 0) - Math.floor(Date.now() / 1000));
        return {
            expired: left <= 0,
            units: [
                { label: 'Days', value: Math.floor(left / 86400) },
                { label: 'Hours', value: Math.floor(left / 3600) % 24 },
                { label: 'Mins', value: Math.floor(left / 60) % 60 },
                { label: 'Secs', value: left % 60 },
            ],
        };
    };

    const [state, setState] = useState(calc);

    useEffect(() => {
        if (!endsAtUnix) return;
        const t = setInterval(() => setState(calc()), 1000);
        return () => clearInterval(t);
    }, [endsAtUnix]);

    return state;
}
