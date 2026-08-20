import { usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { CsrfField } from '../../Shared/AuthCard';

export default function ForgotPassword({ old }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    return (
        <AuthCard title="Reset your password">
            {/* Reset by email (link) */}
            <p className="text-sm text-ink-700/60 mt-4 mb-2">Enter your email and we'll send you a reset link.</p>
            <form action="/password/email" method="POST" className="space-y-3">
                <CsrfField />
                <div><label className="label">Email address</label><input type="email" name="email" defaultValue={old.email || ''} className="input" required /></div>
                <button className="btn-primary w-full">Email me a reset link</button>
            </form>

            <div className="flex items-center gap-3 my-6 text-xs text-ink-700/40">
                <span className="h-px flex-1 bg-ink-100"></span>OR<span className="h-px flex-1 bg-ink-100"></span>
            </div>

            {/* Reset by SMS OTP */}
            <p className="text-sm text-ink-700/60 mb-2">Prefer SMS? We'll text a 6-digit code to your mobile.</p>
            <form action="/password/forgot" method="POST" className="space-y-3">
                <CsrfField />
                <div><label className="label">Mobile number</label><input name="phone" defaultValue={old.phone || ''} placeholder="01XXXXXXXXX" className="input" required /></div>
                <button className="btn-outline w-full">Send code by SMS</button>
            </form>

            <p className="text-center text-sm mt-6"><a href={urls.login} className="text-gold-700 hover:underline">Back to login</a></p>
        </AuthCard>
    );
}

ForgotPassword.layout = (page) => <Layout>{page}</Layout>;
