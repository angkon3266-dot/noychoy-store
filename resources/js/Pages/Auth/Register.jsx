import { Link, useForm, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { keepOnErrors } from '../../Shared/AuthCard';

export default function Register({ old }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const form = useForm({ name: old.name || '', phone: old.phone || '', email: old.email || '', password: '', password_confirmation: '' });
    const f = (k, type = 'text') => ({ type, value: form.data[k], onChange: (e) => form.setData(k, e.target.value) });

    const submit = (e) => {
        e.preventDefault();
        form.post(urls.register, { ...keepOnErrors, onError: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthCard title="Create your account">
            <form onSubmit={submit} className="mt-6 space-y-4">
                <div><label className="label">Full name<input {...f('name')} className="input" required autoComplete="name" /></label></div>
                <div><label className="label">Mobile number *<input {...f('phone')} placeholder="01XXXXXXXXX" className="input" required autoComplete="tel" inputMode="numeric" /></label></div>
                <div><label className="label">Email <span className="text-ink-700/70 font-normal">(optional)</span><input {...f('email', 'email')} className="input" autoComplete="email" /></label></div>
                <div><label className="label">Password<input {...f('password', 'password')} className="input" required autoComplete="new-password" /></label></div>
                <div><label className="label">Confirm password<input {...f('password_confirmation', 'password')} className="input" required autoComplete="new-password" /></label></div>
                <button className="btn-primary w-full" disabled={form.processing}>{form.processing ? 'Creating account…' : 'Register'}</button>
            </form>
            <p className="text-center text-sm mt-4">Already have an account? <Link href={urls.login} className="text-gold-700 hover:underline">Log in</Link></p>
        </AuthCard>
    );
}

Register.layout = (page) => <Layout>{page}</Layout>;
