// Client-side money formatting — mirrors app/helpers.php money():
// currency symbol + number_format($amount, 0).
export function money(amount, symbol = '৳') {
    return symbol + Math.round(Number(amount) || 0).toLocaleString('en-US');
}

export function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

/** fetch() wrapper matching what the Alpine cart store sent. */
export async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
            ...(options.headers || {}),
        },
    });
    if (!res.ok) throw new Error(String(res.status));
    return res.json();
}
