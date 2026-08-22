import { Link, usePage } from '@inertiajs/react';

// During the incremental migration only some routes return Inertia responses.
// SPA-navigate (fast, no reload) to those; hard-navigate everywhere else so a
// Blade destination never receives an Inertia XHR it can't answer.
const INERTIA_PREFIXES = [
    '/shop', '/best-sellers', '/category/', '/product/', '/cart',
    '/discover', '/contact', '/about', '/privacy-policy', '/terms-and-conditions', '/refund-policy', '/track',
    // /account pages are Inertia — but /account/n/{id} (notification click-through)
    // redirects to arbitrary admin-authored URLs, so it must stay a full load.
    '/account',
    '/checkout', '/order/',
    // Auth pages are Inertia; a signed-in customer hitting them is redirected
    // to "/" by the guest middleware, which is Inertia too.
    '/login', '/register', '/password/',
];

const HARD_NAV = ['/account/n/'];

export function isInertiaUrl(url, inertiaHome = false) {
    if (!url) return false;
    let path;
    try {
        path = new URL(url, window.location.origin);
    } catch (e) {
        return false;
    }
    if (path.origin !== window.location.origin) return false;
    const p = path.pathname;
    if (HARD_NAV.some((prefix) => p.startsWith(prefix))) return false;
    // "/" is Inertia only while the active homepage template is the React one.
    if (p === '/') return inertiaHome;
    // /cart matches exactly; /cart/add etc. are POST endpoints, never links.
    return INERTIA_PREFIXES.some((prefix) =>
        prefix.endsWith('/') ? p.startsWith(prefix) : (p === prefix || p.startsWith(prefix + '?')));
}

export default function SmartLink({ href, children, ...rest }) {
    const { props } = usePage();
    // target="_blank" (admin "open in new tab" menu items) must stay a real <a>.
    if (!rest.target && isInertiaUrl(href, !!props.chrome?.inertiaHome)) {
        return <Link href={href} {...rest}>{children}</Link>;
    }
    return <a href={href} {...rest}>{children}</a>;
}
