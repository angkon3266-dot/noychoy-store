import { usePage } from '@inertiajs/react';
import { csrf } from './format';

// Shared shell for the auth pages: centered card, heading, flash + validation
// banners. Auth forms post as PLAIN form submissions (not Inertia XHR) — their
// redirect targets (intended URLs, /account) may still be Blade pages, and a
// browser-followed redirect works for both worlds.
export default function AuthCard({ title, subtitle = null, children }) {
    const { props } = usePage();
    const errors = props.errors || {};
    const errorList = Object.values(errors).flat();

    return (
        <div className="mx-auto max-w-md px-4 py-12">
            <div className="card p-8">
                <h1 className="font-display text-2xl font-semibold text-center">{title}</h1>
                {subtitle && <p className="text-center text-sm text-ink-700/60 mt-1">{subtitle}</p>}

                {props.flash?.success && (
                    <div className="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2 text-sm mt-4">{props.flash.success}</div>
                )}
                {props.flash?.error && (
                    <div className="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{props.flash.error}</div>
                )}
                {errorList.length > 0 && (
                    <div className="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">
                        {errorList.length === 1 ? errorList[0] : (
                            <ul className="list-disc list-inside">{errorList.map((e, i) => <li key={i}>{e}</li>)}</ul>
                        )}
                    </div>
                )}

                {children}
            </div>
        </div>
    );
}

/** Hidden CSRF field for plain (non-Inertia) form posts. */
export function CsrfField() {
    return <input type="hidden" name="_token" value={csrf()} />;
}
