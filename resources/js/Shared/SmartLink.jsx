import { Link } from '@inertiajs/react';

// During the incremental migration only some routes return Inertia responses.
// SPA-navigate (fast, no reload) to those; hard-navigate everywhere else so a
// Blade destination never receives an Inertia XHR it can't answer.
const INERTIA_PREFIXES = ['/shop', '/best-sellers', '/category/', '/product/', '/cart'];

export function isInertiaUrl(url) {
    if (!url) return false;
    let path;
    try {
        path = new URL(url, window.location.origin);
    } catch (e) {
        return false;
    }
    if (path.origin !== window.location.origin) return false;
    const p = path.pathname;
    // /cart matches exactly; /cart/add etc. are POST endpoints, never links.
    return INERTIA_PREFIXES.some((prefix) =>
        prefix.endsWith('/') ? p.startsWith(prefix) : (p === prefix || p.startsWith(prefix + '?')));
}

export default function SmartLink({ href, children, ...rest }) {
    // target="_blank" (admin "open in new tab" menu items) must stay a real <a>.
    if (!rest.target && isInertiaUrl(href)) {
        return <Link href={href} {...rest}>{children}</Link>;
    }
    return <a href={href} {...rest}>{children}</a>;
}
