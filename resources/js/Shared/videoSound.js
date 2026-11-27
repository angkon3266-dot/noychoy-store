import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';

/**
 * Sound for the store's clips: on by default, and only while a clip is on
 * screen (owner, 2026-11-27: "can the volume be on rather than muted … when
 * scrolled up or down, video and sound will be paused").
 *
 * Browsers decide when a page may make noise. Chrome, Safari and every phone
 * refuse sound until the visitor has tapped, clicked or pressed a key on the
 * page; scrolling does not count. So:
 *   - each clip first tries to play with sound (desktop Chrome often allows
 *     it on a site people come back to), and falls back to silent;
 *   - the visitor's first tap anywhere turns the sound up right then, inside
 *     the tap, which is the only moment iPhones accept it;
 *   - Inertia keeps the page alive between pages, so after that tap every
 *     clip on every page plays with sound.
 *
 * Every registered clip plays while at least 35% of it is on screen and
 * pauses when it leaves (or the tab is hidden). Only one clip is audible: the
 * most visible one that has sound. Muting is remembered on this device.
 */

const KEY = 'nc_video_sound';
const SHOWN = 0.35;
const hasWindow = typeof window !== 'undefined';

let wanted = true;
try { wanted = !hasWindow || window.localStorage.getItem(KEY) !== 'off'; } catch (e) { /* private mode */ }
let unlocked = hasWindow && !!window.navigator?.userActivation?.hasBeenActive;

const entries = new Set();          // { el, ratio, opts, userPaused, tried }
const listeners = new Set();
let snapshot = { wanted, unlocked };

function emit() {
    snapshot = { wanted, unlocked };
    listeners.forEach((l) => l());
}

function visible(e) {
    return e.opts.enabled && e.ratio >= SHOWN && document.visibilityState === 'visible';
}

function play(el) {
    const p = el.play?.();
    return p && typeof p.then === 'function' ? p : Promise.resolve();
}

/** Bring every clip in line with what is on screen and what the visitor wants. */
function reconcile() {
    if (!hasWindow) return;

    // A clip the shopper started themselves (the product gallery's player)
    // and is listening to keeps the floor.
    const listening = [...entries].some((e) => !e.opts.managesSound && visible(e) && !e.el.paused && !e.el.muted);
    const focus = listening ? null : [...entries]
        .filter((e) => e.opts.managesSound && e.opts.sound && visible(e) && !e.userPaused)
        .sort((a, b) => b.ratio - a.ratio)[0] || null;

    entries.forEach((e) => {
        const { el, opts } = e;
        if (!visible(e)) {
            if (!el.paused) el.pause();
            return;
        }

        if (opts.managesSound) {
            const loud = e === focus && wanted;
            if (loud && !unlocked && !e.tried && el.currentSrc) {
                // Ask once with sound; a refusal costs nothing but a console line.
                e.tried = true;
                el.muted = false;
                play(el).then(() => {
                    if (!el.muted) { unlocked = true; emit(); }
                }, () => {
                    el.muted = true;
                    if (opts.autoplay && !e.userPaused) play(el).catch(() => {});
                });
                return;
            }
            el.muted = !(loud && unlocked);
        }

        if (opts.autoplay && !e.userPaused && el.paused && el.currentSrc) play(el).catch(() => {});
    });
}

if (hasWindow) {
    // The first tap, click or key press. Runs inside the gesture so that
    // iPhones accept the unmute; scrolling is not a gesture to a browser.
    // Only an event the browser itself counts as a gesture will do: a finger
    // going down, or lifting after a scroll, is not one, and Chrome pauses a
    // clip that is unmuted outside a gesture.
    const EVENTS = ['pointerdown', 'pointerup', 'touchend', 'mousedown', 'click', 'keydown'];
    const unlock = () => {
        if (unlocked) return;
        if (window.navigator.userActivation && !window.navigator.userActivation.isActive) return;
        unlocked = true;
        EVENTS.forEach((t) => window.removeEventListener(t, unlock, true));
        reconcile();
        emit();
    };
    if (!unlocked) {
        EVENTS.forEach((t) => window.addEventListener(t, unlock, true));
    }
    document.addEventListener('visibilitychange', reconcile);
}

const io = hasWindow && 'IntersectionObserver' in window
    ? new IntersectionObserver((list) => {
        list.forEach((it) => {
            const e = [...entries].find((x) => x.el === it.target);
            if (e) e.ratio = it.isIntersecting ? it.intersectionRatio : 0;
        });
        reconcile();
    }, { threshold: [0, 0.2, SHOWN, 0.5, 0.75, 1] })
    : null;

/** The visitor's sound switch, shared by every speaker button on the site. */
export function setSoundWanted(on) {
    wanted = !!on;
    try { window.localStorage.setItem(KEY, wanted ? 'on' : 'off'); } catch (e) { /* private mode */ }
    // A button press is a gesture: the browser will let the sound through.
    unlocked = true;
    reconcile();
    emit();
}

export function useSoundPref() {
    return useSyncExternalStore(
        (l) => { listeners.add(l); return () => listeners.delete(l); },
        () => snapshot,
        () => snapshot,
    );
}

/**
 * Register a <video> with the site's sound and visibility rules. Returns a
 * callback ref plus controls for a play/pause button.
 *
 *   enabled       false while the clip is hidden (an inactive hero slide)
 *   sound         the clip has sound worth hearing
 *   autoplay      play by itself when on screen (false: the shopper starts it)
 *   managesSound  false: leave muted alone (a player with its own controls)
 */
export function useAutoVideo({ enabled = true, sound = true, autoplay = true, managesSound = true } = {}) {
    const entry = useRef(null);
    const opts = { enabled, sound, autoplay, managesSound };

    useEffect(() => {
        if (!entry.current) return;
        entry.current.opts = opts;
        reconcile();
    }, [enabled, sound, autoplay, managesSound]); // eslint-disable-line react-hooks/exhaustive-deps

    const optsRef = useRef(opts);
    optsRef.current = opts;

    const ref = useCallback((el) => {
        if (entry.current) {
            io?.unobserve(entry.current.el);
            entry.current.el.removeEventListener('loadeddata', reconcile);
            entries.delete(entry.current);
            entry.current = null;
        }
        if (!el) return;
        const e = { el, ratio: io ? 0 : 1, opts: optsRef.current, userPaused: false, tried: false };
        entry.current = e;
        entries.add(e);
        // A source attached later (lazy clips) is picked up once it has a frame.
        el.addEventListener('loadeddata', reconcile);
        if (io) io.observe(el); else reconcile();
    }, []);

    /** The shopper's own play/pause button. */
    const setUserPaused = useCallback((paused) => {
        const e = entry.current;
        if (!e) return;
        e.userPaused = paused;
        if (paused) e.el.pause();
        else play(e.el).catch(() => {});
        reconcile();
    }, []);

    return { ref, setUserPaused };
}
