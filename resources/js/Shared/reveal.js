// Scroll-reveal for the React storefront — a port of the [data-shop] block at
// the bottom of app.js. Sections fade up as they enter the viewport; cards
// stagger. Skipped for reduced-motion users; content stays visible without it.
let observer = null;

export function initReveal() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (!('IntersectionObserver' in window)) return;

    observer?.disconnect();
    observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('reveal-in');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    const activate = (el, delay) => {
        if (el.classList.contains('reveal-init')) return;   // already armed
        if (delay) el.style.setProperty('--reveal-delay', delay);
        el.classList.add('reveal-init');
        if (el.getBoundingClientRect().top < window.innerHeight - 20) {
            el.classList.add('reveal-in');
        } else {
            observer.observe(el);
        }
    };

    document.querySelectorAll('main section').forEach((el) => activate(el, ''));
    document.querySelectorAll('main .group.relative.block, main .card')
        .forEach((el, i) => activate(el, `${(i % 4) * 0.08}s`));
}
