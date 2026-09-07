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

    const m = props.chrome?.membership;
    const perks = [
        m?.pct ? `${m.pct}% off every piece, applied automatically at checkout` : null,
        m?.pointsPer1000 ? `${m.pointsPer1000} points for every ৳1,000 you spend — redeem them for taka off` : null,
        m?.signupPoints ? `${m.signupPoints} welcome points the moment you join` : null,
        m?.tiers?.length ? `${m.tiers.map((t) => t.label).join(' → ')} tiers: ${m.tiers[m.tiers.length - 1].perk}` : null,
        'Order history, saved addresses and one-tap reorders',
    ].filter(Boolean);

    return (
        <AuthCard title="Create your account" subtitle={m?.text || m?.pitch || undefined}>
            {/* The form used to be five bare inputs — it never said what
                joining was worth. */}
            <ul className="mt-5 space-y-1.5 text-sm text-ink-800">
                {perks.map((p) => (
                    <li key={p} className="flex items-start gap-2"><span className="mt-[3px] grid h-4 w-4 shrink-0 place-items-center rounded-full bg-gold-100 text-gold-700 text-[10px]">✓</span><span>{p}</span></li>
                ))}
            </ul>
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
