import { Link, useForm, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { keepOnErrors } from '../../Shared/AuthCard';

export default function ForgotPassword({ old }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const emailForm = useForm({ email: old.email || '' });
    const smsForm = useForm({ phone: old.phone || '' });

    return (
        <AuthCard title="Reset your password">
            <p className="text-sm text-ink-700/70 mt-4 mb-2">Enter your email and we'll send you a reset link.</p>
            <form onSubmit={(e) => { e.preventDefault(); emailForm.post('/password/email', keepOnErrors); }} className="space-y-3">
                <div><label className="label">Email address<input type="email" value={emailForm.data.email} onChange={(e) => emailForm.setData('email', e.target.value)} className="input" required autoComplete="email" /></label></div>
                <button className="btn-primary w-full" disabled={emailForm.processing}>{emailForm.processing ? 'Sending…' : 'Email me a reset link'}</button>
            </form>

            <div aria-hidden="true" className="flex items-center gap-3 my-6 text-xs text-ink-700/70">
                <span className="h-px flex-1 bg-ink-100"></span>OR<span className="h-px flex-1 bg-ink-100"></span>
            </div>

            <p className="text-sm text-ink-700/70 mb-2">Prefer SMS? We'll text a 6-digit code to your mobile.</p>
            <form onSubmit={(e) => { e.preventDefault(); smsForm.post('/password/forgot', keepOnErrors); }} className="space-y-3">
                <div><label className="label">Mobile number<input value={smsForm.data.phone} onChange={(e) => smsForm.setData('phone', e.target.value)} placeholder="01XXXXXXXXX" className="input" required autoComplete="tel" inputMode="numeric" /></label></div>
                <button className="btn-outline w-full" disabled={smsForm.processing}>{smsForm.processing ? 'Sending…' : 'Send code by SMS'}</button>
            </form>

            <p className="text-center text-sm mt-6"><Link href={urls.login} className="text-gold-700 hover:underline">Back to login</Link></p>
        </AuthCard>
    );
}

ForgotPassword.layout = (page) => <Layout>{page}</Layout>;
