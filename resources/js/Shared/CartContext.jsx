import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { fetchJson } from './format';

// React port of the Alpine `cart` store: badge count, mini-cart drawer,
// optimistic add with toast. Talks to the same JSON endpoints
// (CartController@mini / @add / @remove) with the same payload contract.
const CartContext = createContext(null);

export function CartProvider({ children }) {
    const { props } = usePage();
    const [count, setCount] = useState(props.cart?.count ?? 0);
    const [items, setItems] = useState([]);
    const [subtotalText, setSubtotalText] = useState('');
    const [discountLines, setDiscountLines] = useState([]);
    const [hints, setHints] = useState([]);
    const [freeShipping, setFreeShipping] = useState(false);
    const [drawer, setDrawer] = useState(false);
    const [toast, setToast] = useState(null);
    const busyRef = useRef(false);
    const toastTimer = useRef(null);

    // Server-rendered count wins after every Inertia navigation/redirect.
    useEffect(() => {
        if (props.cart?.count !== undefined) setCount(props.cart.count);
    }, [props.cart?.count]);

    const apply = useCallback((data) => {
        setCount(data.count);
        setItems(data.items || []);
        setSubtotalText(data.subtotal_text || '');
        setDiscountLines(data.discount_lines || []);
        setHints(data.hints || []);
        setFreeShipping(!!data.free_shipping);
    }, []);

    const showToast = useCallback((msg) => {
        setToast(msg);
        clearTimeout(toastTimer.current);
        toastTimer.current = setTimeout(() => setToast(null), 3000);
    }, []);

    const refresh = useCallback(async () => {
        try {
            apply(await fetchJson(usePageSafeUrl(props, 'cartMini') || '/cart/mini'));
        } catch (e) { /* keep the last known state */ }
    }, [apply, props]);

    /** POST an add-to-cart endpoint with fields; mirrors Alpine $store.cart.add. */
    const add = useCallback(async (action, fields = {}) => {
        if (busyRef.current) return false;      // rapid double-taps add once
        busyRef.current = true;
        try {
            const body = new FormData();
            Object.entries(fields).forEach(([k, v]) => body.append(k, v ?? ''));
            const data = await fetchJson(action, { method: 'POST', body });
            apply(data);
            showToast((data.added || 'Item') + ' added to cart ✓');
            return true;
        } catch (e) {
            showToast('Could not add to cart — please try again.');
            return false;
        } finally {
            setTimeout(() => { busyRef.current = false; }, 800);
        }
    }, [apply, showToast]);

    const remove = useCallback(async (key) => {
        try {
            apply(await fetchJson('/cart/remove', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key }),
            }));
        } catch (e) {
            refresh();
        }
    }, [apply, refresh]);

    const openDrawer = useCallback(() => {
        refresh();
        setDrawer(true);
    }, [refresh]);

    return (
        <CartContext.Provider value={{
            count, items, subtotalText, discountLines, hints, freeShipping,
            drawer, setDrawer, openDrawer, toast, showToast, add, remove, refresh,
        }}>
            {children}
        </CartContext.Provider>
    );
}

function usePageSafeUrl(props, key) {
    return props.chrome?.urls?.[key];
}

export function useCart() {
    return useContext(CartContext);
}
