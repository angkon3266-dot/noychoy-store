import { usePage } from '@inertiajs/react';

// Shared shell for the auth pages: centered card, heading, flash + validation
// banners. Forms submit through Inertia (no page reload); every redirect target
// they can land on (/account, /login, the OTP page, "/") is an Inertia page.
export default function AuthCard({ title, subtitle = null, children }) {
    const { props } = usePage();
    const errors = props.errors || {};
    const errorList = Object.values(errors).flat();

    return (
        <div className="mx-auto max-w-md px-4 py-12">
            <div className="card p-8">
                <h1 className="font-display text-2xl font-semibold text-center">{title}</h1>
                {subtitle && <p className="text-center text-sm text-ink-700/60 mt-1">{subtitle}</p>}

                {/* Flash is rendered once, by the Layout; validation errors are
                    page-local and belong beside the form. */}
                {errorList.length > 0 && (
                    <div className="rounded-md bg-danger-50 border border-danger-200 text-danger-800 px-4 py-2 text-sm mt-4" role="alert">
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

/** Keep the typed values when the server bounces back with validation errors. */
export const keepOnErrors = { preserveState: (page) => Object.keys(page.props.errors || {}).length > 0 };
