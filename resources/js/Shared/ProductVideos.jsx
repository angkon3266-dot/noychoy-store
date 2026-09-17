import { useEffect, useMemo, useRef, useState } from 'react';

/*
 * The product page's video section (owner, 2026-09-17).
 *
 * The owner's call: a product's clips get a section of their own directly
 * under the gallery and buy box, and they play by themselves, MUTED, once
 * scrolled into view, pausing again when scrolled away. About 85 of the 105
 * products carry an uploaded MP4, and until now it only played for a shopper
 * who found the video slide at the end of the gallery and tapped play.
 *
 * What shapes the code below:
 *
 * - Data. Uploads run to 50 MB and most shoppers are on Bangladeshi mobile
 *   data. Nothing is fetched until the section is within a screen of the
 *   viewport: preload stays "none" until then, and rises only to "metadata",
 *   only for the one clip that will play. Save-Data and prefers-reduced-motion
 *   switch autoplay off altogether: the poster and a play button instead.
 *   With autoplay off, a press of play is a one-off. Were it to switch
 *   autoplay back on, the next clip swiped to, or this one scrolled back to,
 *   would start (and download) by itself: the very thing the setting asked
 *   not to happen. So the section goes back to waiting for a tap whenever it
 *   leaves view or the slide changes.
 *
 * - A dropped connection is not a broken clip. On mobile data a 50 MB upload
 *   can lose its connection part-way, and the browser then reports a network
 *   error and stops fetching for good. Only a decode or unsupported-source
 *   error means the file itself cannot play here; a network error keeps the
 *   play button, which reloads the file before playing it again.
 *
 * - Which links make it. The admin accepts any link as a product video, and
 *   video_meta() types anything that is not YouTube or Vimeo as a file, with
 *   the link as its source. The CSP's media-src allows only this site (plus
 *   data: and blob:), so another site's .mp4 or a Facebook reel is refused
 *   before a byte loads, and a section built around it could only say the
 *   clip cannot play. Such clips are left out here, and with none left the
 *   section is not drawn at all.
 *
 * - Autoplay policy. Browsers autoplay only muted media, and React never
 *   writes the `muted` attribute (only the property, on its own schedule), so
 *   the property is set on the element directly right before every play()
 *   rather than trusted to the render. A refused play() (iOS Low Power Mode
 *   refuses even muted autoplay) hands over to the play button rather than
 *   leaving a "playing" UI over a frozen poster.
 *
 * - The phone's chrome. The sticky buy bar and the bottom nav cover about
 *   130px at the foot of the screen, so the viewport is measured without
 *   them. A clip counts as in view once 60% of it (or 60% of that screen) is
 *   showing, and as scrolled away once less than a quarter is; the gap stops
 *   a clip resting on the boundary from flickering between play and pause.
 *
 * - Its own observer. reveal.js observes a section until it first appears and
 *   then lets go, so it cannot say when a clip leaves.
 *
 * - YouTube and Vimeo. The CSP blocks YouTube's iframe_api script, so the
 *   players are steered with the postMessage commands their embeds accept.
 *   An iframe mounts only once the section is first in view, and only for the
 *   slide on screen: a live iframe swallows swipes, so the other slides show
 *   a poster, and the arrows and dots move between them.
 *
 * - Vertical clips. Many uploads are shot on a phone, so nothing is cropped
 *   to 16:9: each clip is letterboxed (object-contain) in a box whose size is
 *   fixed up front, so the page below does not jump as a video loads.
 *
 * - One video at a time, page-wide. Playing here announces VIDEO_PLAY_EVENT
 *   and the gallery stops its clip; when a shopper starts one in the gallery
 *   it announces too, and this section pauses until it has been scrolled
 *   right out of view and back, or play is pressed here.
 */

export const VIDEO_PLAY_EVENT = 'noychoy:video-play';

const SOURCE = 'product-videos';

/** Tell the page a video has started, so any other one stops. */
export function announceVideoPlay(source) {
    if (typeof window === 'undefined') return;
    window.dispatchEvent(new CustomEvent(VIDEO_PLAY_EVENT, { detail: { source } }));
}

const ENTER = 0.6;
const LEAVE = 0.25;
const COVERED_BOTTOM_PX = 130;
const THRESHOLDS = Array.from({ length: 21 }, (_, i) => i / 20);

function prefersReducedMotion() {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
        return false;
    }
}

function autoplayAllowed() {
    if (typeof window === 'undefined') return false;
    if (prefersReducedMotion()) return false;
    if (navigator.connection?.saveData) return false;
    return 'IntersectionObserver' in window;
}

function quietly(promise) {
    if (promise && typeof promise.catch === 'function') promise.catch(() => {});
}

// MediaError codes that mean the file itself cannot play in this browser.
// MEDIA_ERR_ABORTED (1) and MEDIA_ERR_NETWORK (2) say nothing about the file.
const MEDIA_ERR_DECODE = 3;
const MEDIA_ERR_SRC_NOT_SUPPORTED = 4;

/**
 * Whether the CSP's media-src ('self' data: blob:) lets this page load the
 * link. Uploads are stored as full URLs built from APP_URL, which can still
 * be http:// while the page is https:// (AppServiceProvider forces https on
 * generated links, not on Storage URLs); browsers upgrade such a mixed-content
 * media request to https before the CSP check, so it is the host and port
 * that decide here, not the scheme.
 */
function servedHere(src) {
    if (typeof window === 'undefined') return false;
    try {
        const url = new URL(src, window.location.href);
        if (url.protocol === 'data:' || url.protocol === 'blob:') return true;
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
        return url.hostname === window.location.hostname && url.port === window.location.port;
    } catch (e) {
        return false;
    }
}

const COMMANDS = {
    youtube: {
        origin: 'https://www.youtube.com',
        play: [{ event: 'command', func: 'playVideo', args: '' }],
        pause: [{ event: 'command', func: 'pauseVideo', args: '' }],
        mute: [{ event: 'command', func: 'mute', args: '' }],
        unmute: [{ event: 'command', func: 'unMute', args: '' }],
    },
    vimeo: {
        origin: 'https://player.vimeo.com',
        play: [{ method: 'play' }],
        pause: [{ method: 'pause' }],
        mute: [{ method: 'setMuted', value: true }, { method: 'setVolume', value: 0 }],
        unmute: [{ method: 'setMuted', value: false }, { method: 'setVolume', value: 1 }],
    },
};

function command(frame, type, action) {
    const spec = COMMANDS[type];
    const win = frame?.contentWindow;
    if (!spec || !win) return;
    spec[action].forEach((message) => {
        try {
            win.postMessage(JSON.stringify(message), spec.origin);
        } catch (e) {
            // The frame is mid-navigation; the load retries send it again.
        }
    });
}

function embedSrc(video) {
    if (video.type === 'youtube') {
        const id = (video.embed.match(/\/embed\/([^/?#]+)/) || [])[1] || '';
        // loop only works on a single embed when it is also its own playlist.
        const query = new URLSearchParams({
            autoplay: '1', mute: '1', playsinline: '1', loop: '1', playlist: id,
            rel: '0', modestbranding: '1', enablejsapi: '1', origin: window.location.origin,
        });
        return `${video.embed}?${query}`;
    }
    return `${video.embed}?autoplay=1&muted=1&playsinline=1&loop=1`;
}

export default function ProductVideos({ videos, name, poster }) {
    const clips = useMemo(
        () => (videos || []).filter((v) => v && (v.type === 'file' ? !!v.src && servedHere(v.src) : !!v.embed && !!COMMANDS[v.type])),
        [videos],
    );
    if (!clips.length) return null;
    return <VideoSection videos={clips} name={name} poster={poster} />;
}

function VideoSection({ videos, name, poster }) {
    const total = videos.length;

    const [autoplay] = useState(autoplayAllowed);
    const [active, setActive] = useState(0);         // settled slide: the only one that plays
    const [shown, setShown] = useState(0);           // slide under the scroll position: arrows, dots
    const [inView, setInView] = useState(false);
    const [near, setNear] = useState(false);
    const [pageVisible, setPageVisible] = useState(true);
    const [userPaused, setUserPaused] = useState(!autoplay);
    const [yielded, setYielded] = useState(false);   // another video on the page started
    const [muted, setMuted] = useState(true);
    const [live, setLive] = useState(null);          // slide whose YouTube/Vimeo iframe is mounted
    const [elPaused, setElPaused] = useState(true);  // the active <video> element really is paused
    const [rolling, setRolling] = useState(false);   // the playing <video> is showing frames
    const [failed, setFailed] = useState({});        // slide index -> the browser cannot play the file
    const [dropped, setDropped] = useState({});      // slide index -> a network error stopped it; play reloads

    const frame = useRef(null);
    const track = useRef(null);
    const videoEls = useRef([]);
    const iframeEl = useRef(null);
    const mutedRef = useRef(true);
    const shouldPlayRef = useRef(false);
    const inViewRef = useRef(false);
    const intersecting = useRef(false);
    const userStarted = useRef(false);
    const raf = useRef(0);
    const settle = useRef(0);
    const retries = useRef([]);
    const wanted = useRef(null);                     // slide whose play was pressed before the swipe to it settled

    const shouldPlay = inView && pageVisible && !userPaused && !yielded;
    const current = videos[active];
    // What the buttons show. For an uploaded file that is the element's own
    // state, not just the intent: the browser can pause a clip by itself (a
    // headset unplugged, a background-tab saving), and the shopper should
    // then see a play button that works rather than a pause that does
    // nothing. The intent is deliberately left alone in that case, so
    // autoplay still resumes the next time the section scrolls into view.
    // A clip whose connection dropped is not running either, however the
    // element reports itself: it has stopped fetching and will not recover.
    const running = shouldPlay && (current?.type !== 'file' || (!elPaused && !dropped[active]));

    useEffect(() => { shouldPlayRef.current = shouldPlay; }, [shouldPlay]);

    // In view / scrolled away, and a second, looser watch that lets the one
    // clip due to play start fetching a screen before it arrives.
    useEffect(() => {
        const el = frame.current;
        if (!el || !('IntersectionObserver' in window)) return undefined;

        const watch = new IntersectionObserver((entries) => {
            // A fast fling can queue several crossings; the last is current.
            const entry = entries[entries.length - 1];
            const rootHeight = entry.rootBounds?.height || (window.innerHeight - COVERED_BOTTOM_PX);
            const fraction = Math.max(
                entry.intersectionRatio,
                rootHeight > 0 ? entry.intersectionRect.height / rootHeight : 0,
            );
            intersecting.current = entry.isIntersecting;

            let next = inViewRef.current;
            if (fraction >= ENTER) {
                next = true;
            } else if (fraction < LEAVE && !(userStarted.current && entry.isIntersecting)) {
                // A clip the shopper started by hand keeps going until it is
                // fully off screen: they pressed play on what they could see.
                next = false;
            }
            if (!entry.isIntersecting) {
                userStarted.current = false;
                setYielded(false);
            }
            if (next !== inViewRef.current) {
                inViewRef.current = next;
                setInView(next);
                // Autoplay off: the press that started it was for that one
                // viewing, so coming back waits for another.
                if (!next && !autoplay) setUserPaused(true);
            }
        }, { threshold: THRESHOLDS, rootMargin: `0px 0px -${COVERED_BOTTOM_PX}px 0px` });

        const approach = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;
            setNear(true);
            approach.disconnect();
        }, { rootMargin: '100% 0px 100% 0px' });

        watch.observe(el);
        approach.observe(el);
        return () => {
            watch.disconnect();
            approach.disconnect();
        };
    }, []);

    useEffect(() => {
        const onVisibility = () => setPageVisible(document.visibilityState !== 'hidden');
        onVisibility();
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    // Another video started (the gallery's tap-to-play). Only matters while
    // any of this section is on screen; one that starts while it is out of
    // sight is left to be stopped when this section scrolls into view.
    useEffect(() => {
        const onOtherVideo = (e) => {
            if (e.detail?.source === SOURCE) return;
            if (intersecting.current) setYielded(true);
        };
        window.addEventListener(VIDEO_PLAY_EVENT, onOtherVideo);
        return () => window.removeEventListener(VIDEO_PLAY_EVENT, onOtherVideo);
    }, []);

    useEffect(() => () => {
        if (raf.current) cancelAnimationFrame(raf.current);
        clearTimeout(settle.current);
        retries.current.forEach(clearTimeout);
    }, []);

    useEffect(() => {
        setRolling(false);
        setElPaused(videoEls.current[active]?.paused ?? true);
    }, [active]);

    // Uploaded files: drive every <video> from the intent above.
    useEffect(() => {
        videoEls.current.forEach((el, i) => {
            if (!el) return;
            if (i !== active || !shouldPlay || failed[i] || dropped[i]) {
                if (!el.paused) el.pause();
                return;
            }
            el.muted = mutedRef.current;
            const started = el.play();
            if (!started || typeof started.catch !== 'function') return;
            started.catch((err) => {
                if (err?.name !== 'NotAllowedError' || !shouldPlayRef.current) return;
                if (!el.muted) {
                    // Sound was refused without a fresh tap: carry on muted.
                    el.muted = true;
                    mutedRef.current = true;
                    setMuted(true);
                    el.play().catch((again) => { if (again?.name === 'NotAllowedError') setUserPaused(true); });
                } else {
                    setUserPaused(true);
                }
            });
        });
    }, [active, shouldPlay, failed, dropped]);

    // YouTube / Vimeo: mount the embed the first time the slide should play
    // (it autoplays muted from its URL), then steer it by postMessage.
    useEffect(() => {
        if (!current || current.type === 'file') return;
        if (live !== active) {
            if (shouldPlay) {
                mutedRef.current = true;   // a fresh embed can only start muted
                setMuted(true);
                setLive(active);
                announceVideoPlay(SOURCE);
            }
            return;
        }
        command(iframeEl.current, current.type, shouldPlay ? 'play' : 'pause');
        if (shouldPlay) announceVideoPlay(SOURCE);
    }, [active, live, shouldPlay, current]);

    // A command sent before the player inside the iframe is listening is
    // simply lost, so a pause or unmute asked for during loading is repeated
    // a few times once the frame has loaded.
    const onFrameLoad = (type) => {
        retries.current.forEach(clearTimeout);
        const sync = () => {
            if (!shouldPlayRef.current) command(iframeEl.current, type, 'pause');
            if (!mutedRef.current) command(iframeEl.current, type, 'unmute');
        };
        retries.current = [300, 1000, 2500].map((ms) => setTimeout(sync, ms));
    };

    const indexAt = () => {
        const el = track.current;
        if (!el || !el.clientWidth) return null;
        return Math.max(0, Math.min(total - 1, Math.round(el.scrollLeft / el.clientWidth)));
    };

    const scrollTo = (i) => {
        const el = track.current;
        if (!el) return;
        const next = Math.max(0, Math.min(total - 1, i));
        el.scrollTo({ left: next * el.clientWidth, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    };

    // The Gallery's pattern: the dots follow the scroll a frame at a time,
    // but the playing clip changes only once the snap has settled, so a
    // swipe across three slides does not start and stop the two in between.
    const onScroll = () => {
        if (!raf.current) {
            raf.current = requestAnimationFrame(() => {
                raf.current = 0;
                const i = indexAt();
                if (i !== null) setShown(i);
            });
        }
        clearTimeout(settle.current);
        settle.current = setTimeout(() => {
            const i = indexAt();
            if (i === null) return;
            if (i !== active) {
                // Play pressed on a slide the swipe had not yet settled on
                // starts it now it has. Otherwise, with autoplay off, the new
                // slide waits for its own tap rather than inheriting the last
                // one's. Set here, in the same batch as the slide, not in an
                // effect after it: for one commit the drive effect would see
                // the new slide with the old intent and call play() on it,
                // which is enough to start the download.
                if (wanted.current === i) {
                    setUserPaused(false);
                } else if (!autoplay) {
                    setUserPaused(true);
                }
            }
            wanted.current = null;
            setActive(i);
            setLive((l) => (l === i ? l : null));
        }, 120);
    };

    const onKey = (e) => {
        if (e.key === 'ArrowLeft') { e.preventDefault(); scrollTo(shown - 1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); scrollTo(shown + 1); }
    };

    const play = (i = active) => {
        userStarted.current = true;
        inViewRef.current = true;
        setInView(true);
        setYielded(false);
        if (i !== active) {
            // Started once the swipe settles on it (onScroll): unpausing now
            // would restart the slide being left instead.
            wanted.current = i;
            scrollTo(i);
            return;
        }
        setUserPaused(false);
        const el = videoEls.current[i];
        if (el && !failed[i]) {
            // Inside the tap itself, where iOS is surest to allow it.
            el.muted = mutedRef.current;
            if (dropped[i]) {
                // After a network error the element has given up on the
                // file; play() alone would not fetch it again.
                setDropped((d) => {
                    const next = { ...d };
                    delete next[i];
                    return next;
                });
                el.load();
            }
            quietly(el.play());
        }
    };

    const pause = () => {
        setUserPaused(true);
        videoEls.current[active]?.pause();
    };

    const toggleMute = () => {
        const next = !mutedRef.current;
        mutedRef.current = next;
        setMuted(next);
        const el = videoEls.current[active];
        if (el) el.muted = next;
        if (current && current.type !== 'file' && live === active) {
            command(iframeEl.current, current.type, next ? 'mute' : 'unmute');
        }
    };

    const label = (i) => (total > 1 ? `video ${i + 1} of ${total}` : 'video');
    const control = 'grid h-9 w-9 place-items-center rounded-full bg-white/90 text-ink-900 shadow hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500';
    const bigPlay = 'grid h-14 w-14 place-items-center rounded-full bg-white/90 text-ink-900 shadow';

    return (
        <section className="mt-12 max-w-3xl" aria-labelledby="product-videos-heading">
            <h2 id="product-videos-heading" className="font-display text-2xl font-semibold mb-4">See it in motion</h2>

            <div className="max-w-xl">
                <div
                    ref={frame}
                    onKeyDown={total > 1 ? onKey : undefined}
                    tabIndex={total > 1 ? 0 : undefined}
                    className="relative w-full aspect-[4/5] sm:aspect-square max-h-[80vh] overflow-hidden rounded-2xl bg-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                >
                    <div
                        ref={track}
                        onScroll={total > 1 ? onScroll : undefined}
                        {...(total > 1 ? { role: 'group', 'aria-roledescription': 'carousel', 'aria-label': `${name} videos` } : {})}
                        className="absolute inset-0 flex overflow-x-auto snap-x snap-mandatory overscroll-x-contain"
                        style={{ scrollbarWidth: 'none', msOverflowStyle: 'none', WebkitOverflowScrolling: 'touch' }}
                    >
                        {videos.map((v, i) => {
                            const runningHere = i === active && running;
                            return (
                                <div
                                    key={`${v.type}-${i}`}
                                    {...(total > 1 ? { role: 'group', 'aria-roledescription': 'slide', 'aria-label': `Video ${i + 1} of ${total}` } : {})}
                                    className="relative h-full w-full shrink-0 snap-center"
                                >
                                    {v.type === 'file' ? (
                                        <>
                                            <video
                                                ref={(el) => { videoEls.current[i] = el; }}
                                                src={v.src}
                                                poster={poster || undefined}
                                                muted={muted}
                                                playsInline
                                                loop
                                                preload={autoplay && near && i === active ? 'metadata' : 'none'}
                                                onPlay={() => {
                                                    announceVideoPlay(SOURCE);
                                                    if (i === active) setElPaused(false);
                                                }}
                                                onPlaying={() => { if (i === active) setRolling(true); }}
                                                onWaiting={() => { if (i === active) setRolling(false); }}
                                                onPause={() => {
                                                    if (i !== active) return;
                                                    setRolling(false);
                                                    setElPaused(true);
                                                }}
                                                onError={(e) => {
                                                    const code = e.currentTarget.error?.code;
                                                    if (code === MEDIA_ERR_DECODE || code === MEDIA_ERR_SRC_NOT_SUPPORTED) {
                                                        setFailed((f) => (f[i] ? f : { ...f, [i]: true }));
                                                    } else {
                                                        setDropped((d) => (d[i] ? d : { ...d, [i]: true }));
                                                    }
                                                }}
                                                onClick={() => (runningHere ? pause() : play(i))}
                                                aria-label={`${name}, ${label(i)}`}
                                                className="absolute inset-0 h-full w-full object-contain"
                                            />
                                            {failed[i] ? (
                                                <p className="absolute inset-x-4 bottom-4 rounded-lg bg-black/60 px-3 py-2 text-center text-xs text-white">
                                                    This clip can't play in this browser.
                                                </p>
                                            ) : runningHere ? (
                                                !rolling && (
                                                    <span aria-hidden="true" className="pointer-events-none absolute left-1/2 top-1/2 -ml-5 -mt-5 h-10 w-10 rounded-full border-2 border-white/30 border-t-white animate-spin motion-reduce:animate-none" />
                                                )
                                            ) : (
                                                <>
                                                    <button type="button" onClick={() => play(i)} aria-label={`Play ${label(i)}`} className={`absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 ${bigPlay}`}>
                                                        <PlayIcon className="w-6 h-6" />
                                                    </button>
                                                    {dropped[i] && (
                                                        <p className="pointer-events-none absolute inset-x-4 bottom-4 rounded-lg bg-black/60 px-3 py-2 text-center text-xs text-white">
                                                            The connection dropped. Tap play to try again.
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                        </>
                                    ) : live === i ? (
                                        <>
                                            <iframe
                                                ref={iframeEl}
                                                src={embedSrc(v)}
                                                title={`${name}, ${label(i)}`}
                                                className="absolute inset-0 h-full w-full"
                                                allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                                                allowFullScreen
                                                onLoad={() => onFrameLoad(v.type)}
                                            />
                                            {!runningHere && (
                                                <button type="button" onClick={() => play(i)} aria-label={`Play ${label(i)}`} className={`absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 ${bigPlay}`}>
                                                    <PlayIcon className="w-6 h-6" />
                                                </button>
                                            )}
                                        </>
                                    ) : (
                                        <button type="button" onClick={() => play(i)} aria-label={`Play ${label(i)}`} className="absolute inset-0 grid place-items-center">
                                            {(v.thumb || poster) && (
                                                <img src={v.thumb || poster} alt="" loading="lazy" decoding="async" className="absolute inset-0 h-full w-full object-contain opacity-80" />
                                            )}
                                            <span className={`relative ${bigPlay}`}>
                                                <PlayIcon className="w-6 h-6" />
                                            </span>
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {!failed[active] && (
                        <div className="absolute right-3 top-3 z-10 flex gap-2">
                            <button type="button" onClick={running ? pause : () => play()} aria-label={running ? 'Pause video' : 'Play video'} className={control}>
                                {running ? <PauseIcon className="w-4 h-4" /> : <PlayIcon className="w-4 h-4" />}
                            </button>
                            <button type="button" onClick={toggleMute} aria-label={muted ? 'Turn sound on' : 'Turn sound off'} className={control}>
                                <SoundIcon off={muted} className="w-5 h-5" />
                            </button>
                        </div>
                    )}

                    {total > 1 && shown > 0 && (
                        <button type="button" onClick={() => scrollTo(shown - 1)} aria-label="Previous video"
                            className="absolute left-3 top-1/2 z-10 -translate-y-1/2 grid h-9 w-9 place-items-center rounded-full border border-ink-100 bg-white/90 text-lg text-ink-900 shadow hover:bg-white">‹</button>
                    )}
                    {total > 1 && shown < total - 1 && (
                        <button type="button" onClick={() => scrollTo(shown + 1)} aria-label="Next video"
                            className="absolute right-3 top-1/2 z-10 -translate-y-1/2 grid h-9 w-9 place-items-center rounded-full border border-ink-100 bg-white/90 text-lg text-ink-900 shadow hover:bg-white">›</button>
                    )}
                </div>

                {total > 1 && (
                    <div className="mt-3 flex items-center justify-center gap-1">
                        {videos.map((v, i) => (
                            <button key={`${v.type}-${i}`} type="button" onClick={() => scrollTo(i)} aria-label={`Show video ${i + 1}`} aria-current={shown === i ? 'true' : undefined} className="grid h-6 w-6 place-items-center">
                                <span className={`block h-2 rounded-full transition-all ${shown === i ? 'w-5 bg-gold-600' : 'w-2 bg-ink-200'}`} />
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}

function PlayIcon({ className }) {
    return <svg aria-hidden="true" className={className} fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>;
}

function PauseIcon({ className }) {
    return <svg aria-hidden="true" className={className} fill="currentColor" viewBox="0 0 24 24"><path d="M6.5 5H10v14H6.5zM14 5h3.5v14H14z" /></svg>;
}

// Icons.jsx has no speaker; these follow its outline style.
function SoundIcon({ off, className }) {
    return (
        <svg aria-hidden="true" className={className} fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" viewBox="0 0 24 24">
            <path d="M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z" />
            {off
                ? <path d="M17.25 9.75l4.5 4.5m0-4.5l-4.5 4.5" />
                : <path d="M16.46 8.29a5.25 5.25 0 010 7.42M19.11 5.64a9 9 0 010 12.72" />}
        </svg>
    );
}
