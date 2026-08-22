import { useForm } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { keepOnErrors } from '../../Shared/AuthCard';

export default function ResetPasswordEmail({ token, email }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const f = (k, type = 'text') => ({ type, value: form.data[k], onChange: (e) => form.setData(k, e.target.value) });

    return (
        <AuthCard title="Choose a new password">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/password/reset-email', { ...keepOnErrors, onError: () => form.reset('password', 'password_confirmation') }); }} className="mt-6 space-y-4">
                <div><label className="label">Email</label><input type="email" value={form.data.email} className="input" readOnly /></div>
                <div><label className="label">New password</label><input {...f('password', 'password')} className="input" required autoComplete="new-password" /></div>
                <div><label className="label">Confirm password</label><input {...f('password_confirmation', 'password')} className="input" required autoComplete="new-password" /></div>
                <button className="btn-primary w-full" disabled={form.processing}>{form.processing ? 'Updating…' : 'Update password'}</button>
            </form>
        </AuthCard>
    );
}

ResetPasswordEmail.layout = (page) => <Layout>{page}</Layout>;
