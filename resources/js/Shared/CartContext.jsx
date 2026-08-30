import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { fetchJson, newEventId } from './format';

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
    const [gift, setGift] = useState(null);
    const [couponNotice, setCouponNotice] = useState(null);
    const [freeShipping, setFreeShipping] = useState(false);
    const [drawer, setDrawer] = useState(false);
    const [toast, setToast] = useState(null);
    const busyRef = useRef(false);
    const toastTimer = useRef(null);
    // What opened the mini-cart. It lives here, not in MiniCart, because the
    // drawer has two triggers (the header button and the bottom nav) and both
    // funnel through openDrawer.
    const cartTrigger = useRef(null);

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
        setGift(data.gift || null);
        setCouponNotice(data.coupon_notice || null);
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
    // Every add goes through here, so this is where AddToCart belongs.
    // It used to live only on the product page, which meant adds from the shop
    // grid, the home carousels, the related strip and the cart suggestions —
    // on a mobile browsing site, most of them — were invisible to both the
    // Pixel and CAPI, and Meta was optimising delivery on a partial signal.
    //
    // `track` carries what the browser event needs; the server fills in the
    // rest from the product. A caller that already made its own event id (the
    // product page, which knows the chosen variant) keeps it — generating a
    // second one here would break the dedup pair.
    const add = useCallback(async (action, fields = {}, track = null) => {
        if (busyRef.current) return false;      // rapid double-taps add once
        busyRef.current = true;
        try {
            const sent = { ...fields };

            if (!sent.event_id) {
                sent.event_id = newEventId('AddToCart');

                if (window.track && track) {
                    window.track('AddToCart', {
                        content_ids: [track.contentId],
                        content_name: track.name,
                        content_type: 'product',
                        value: track.value,
                        currency: 'BDT',
                    }, { eventID: sent.event_id });
                }
            }

            const body = new FormData();
            Object.entries(sent).forEach(([k, v]) => body.append(k, v ?? ''));
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
        cartTrigger.current = document.activeElement;
        refresh();
        setDrawer(true);
    }, [refresh]);

    return (
        <CartContext.Provider value={{
            count, items, subtotalText, discountLines, hints, gift, couponNotice, freeShipping,
            drawer, setDrawer, openDrawer, cartTrigger, toast, showToast, add, remove, refresh,
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
