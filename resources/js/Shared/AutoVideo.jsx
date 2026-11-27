import { useSoundPref, setSoundWanted, useAutoVideo } from './videoSound';

/**
 * A <video> that follows the site's sound rules (videoSound.js): plays while
 * on screen, pauses when scrolled away, and is the one audible clip when it is
 * the most visible clip with sound. Other props go straight to the <video>.
 * It starts muted: the rules turn it up as soon as the browser allows.
 */
export default function AutoVideo({ enabled = true, sound = true, autoplay = true, managesSound = true, ...props }) {
    const { ref } = useAutoVideo({ enabled, sound, autoplay, managesSound });

    return <video ref={ref} muted={managesSound} {...props} />;
}

/** Speaker button bound to the site-wide switch. */
export function SoundButton({ className = '' }) {
    const { wanted, unlocked } = useSoundPref();
    const audible = wanted && unlocked;

    return (
        <button
            type="button"
            onClick={() => setSoundWanted(!audible)}
            aria-pressed={audible}
            aria-label={audible ? 'Turn sound off' : 'Turn sound on'}
            className={className}
        >
            <SpeakerGlyph on={audible} />
        </button>
    );
}

export function SpeakerGlyph({ on, className = 'w-[18px] h-[18px]' }) {
    return (
        <svg aria-hidden="true" className={className} fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" viewBox="0 0 24 24">
            <path d="M11 5L6 9H3v6h3l5 4V5z" />
            {on ? <path d="M15.5 8.5a5 5 0 010 7M18.5 5.5a9 9 0 010 13" /> : <path d="M16 9l5 6M21 9l-5 6" />}
        </svg>
    );
}
