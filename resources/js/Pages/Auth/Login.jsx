import { Link, useForm, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { keepOnErrors } from '../../Shared/AuthCard';

export default function Login({ old }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const form = useForm({ phone: old.phone || '', password: '', remember: false });

    const submit = (e) => {
        e.preventDefault();
        form.post(urls.login, { ...keepOnErrors, onError: () => form.reset('password') });
    };

    return (
        <AuthCard title="Welcome back" subtitle="Log in to your account">
            <form onSubmit={submit} className="mt-6 space-y-4">
                <div><label className="label">Mobile number</label><input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} className="input" required autoComplete="tel" inputMode="numeric" /></div>
                <div><label className="label">Password</label><input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} className="input" required autoComplete="current-password" /></div>
                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Remember me</label>
                    <Link href="/password/forgot" className="text-sm text-gold-700 hover:underline">Forgot password?</Link>
                </div>
                <button className="btn-primary w-full" disabled={form.processing}>{form.processing ? 'Logging in…' : 'Log in'}</button>
            </form>
            <p className="text-center text-sm mt-4">No account? <Link href={urls.register} className="text-gold-700 hover:underline">Register</Link></p>
        </AuthCard>
    );
}

Login.layout = (page) => <Layout>{page}</Layout>;
