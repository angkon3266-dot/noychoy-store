import { useState } from 'react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import Icon, { WhatsApp } from '../../Shared/Icons';

// Invite friends: one link, three ways to send it, and what it has earned.
export default function Referrals({ invite }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(invite.url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (e) {
            window.prompt('Copy your invite link', invite.url);
        }
    };

    const share = async () => {
        if (navigator.share) {
            try { await navigator.share({ title: 'Join me', text: invite.shareText, url: invite.url }); } catch (e) {}
        } else {
            copy();
        }
    };

    return (
        <AccountShell>
            <h1 className="font-display text-2xl font-semibold mb-2">Invite friends</h1>
            <p className="text-sm text-ink-700/70 mb-6">
                Share your link. When a friend joins and their first order is delivered, <strong className="text-ink-900">you both get {invite.points} points</strong>.
            </p>

            {!invite.enabled && (
                <div className="mb-6 rounded-md bg-warning-50 border border-warning-200 text-warning-800 px-4 py-2.5 text-sm">The rewards program is paused right now — your link still works, and points are paid once it is back on.</div>
            )}

            <div className="card p-5 mb-6">
                <label className="label" htmlFor="invite-url">Your invite link</label>
                <div className="flex flex-col sm:flex-row gap-2">
                    <input id="invite-url" readOnly value={invite.url} onFocus={(e) => e.target.select()} className="input flex-1 font-mono text-sm" />
                    <button type="button" onClick={copy} className="btn-outline whitespace-nowrap">{copied ? 'Copied ✓' : 'Copy link'}</button>
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                    <a href={invite.whatsapp} target="_blank" rel="noopener" className="inline-flex items-center gap-2 rounded-full bg-[#25D366] text-white px-4 py-2 text-sm font-medium hover:opacity-90">
                        <WhatsApp className="w-4 h-4" /> Share on WhatsApp
                    </a>
                    <button type="button" onClick={share} className="inline-flex items-center gap-2 rounded-full bg-ink-900 text-white px-4 py-2 text-sm font-medium hover:bg-ink-800">
                        <Icon name="globe" className="w-4 h-4" /> Share…
                    </button>
                </div>
                <p className="mt-3 text-xs text-ink-700/60">Your code: <span className="font-mono">{invite.code}</span>. Any product link with <span className="font-mono">?ref={invite.code}</span> counts too.</p>
            </div>

            <div className="grid sm:grid-cols-3 gap-3 mb-6">
                {[
                    ['Friends joined', invite.stats.joined],
                    ['First orders delivered', invite.stats.rewarded],
                    ['Points earned', invite.stats.points],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-xl border border-ink-100 p-4 text-center">
                        <div className="font-display text-2xl font-semibold">{value}</div>
                        <div className="text-xs text-ink-700/70 mt-1">{label}</div>
                    </div>
                ))}
            </div>

            <div className="rounded-lg bg-ink-50 border border-ink-100 p-4 text-xs text-ink-700/80 space-y-1.5">
                <p className="font-semibold text-ink-900 text-sm mb-1">How it works</p>
                <p>1. Your friend opens your link and creates a free account (or claims one after ordering).</p>
                <p>2. They place their first order and it is delivered.</p>
                <p>3. {invite.points} points land in your balance and in theirs — 100 points = ৳5 off at checkout.</p>
            </div>
        </AccountShell>
    );
}

Referrals.layout = (page) => <Layout>{page}</Layout>;
