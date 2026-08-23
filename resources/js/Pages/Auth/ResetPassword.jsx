import { Link, useForm } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { keepOnErrors } from '../../Shared/AuthCard';

export default function ResetPassword({ phone }) {
    const form = useForm({ phone, otp: '', password: '', password_confirmation: '' });
    const f = (k, type = 'text') => ({ type, value: form.data[k], onChange: (e) => form.setData(k, e.target.value) });

    return (
        <AuthCard title="Enter your code" subtitle="Check your SMS for the 6-digit code, then set a new password.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/password/reset', { ...keepOnErrors, onError: () => form.reset('password', 'password_confirmation') }); }} className="mt-6 space-y-4">
                <div><label className="label">6-digit code<input {...f('otp')} inputMode="numeric" maxLength={6} className="input tracking-widest text-center text-lg" placeholder="••••••" required autoComplete="one-time-code" /></label></div>
                <div><label className="label">New password<input {...f('password', 'password')} className="input" required minLength={6} autoComplete="new-password" /></label></div>
                <div><label className="label">Confirm new password<input {...f('password_confirmation', 'password')} className="input" required autoComplete="new-password" /></label></div>
                <button className="btn-primary w-full" disabled={form.processing}>{form.processing ? 'Resetting…' : 'Reset password'}</button>
            </form>
            <p className="text-center text-sm mt-4"><Link href="/password/forgot" className="text-gold-700 hover:underline">Didn't get a code? Try again</Link></p>
        </AuthCard>
    );
}

ResetPassword.layout = (page) => <Layout>{page}</Layout>;
