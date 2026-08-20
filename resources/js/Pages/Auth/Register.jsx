import { usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { CsrfField } from '../../Shared/AuthCard';

export default function Register({ old }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    return (
        <AuthCard title="Create your account">
            <form action={urls.register} method="POST" className="mt-6 space-y-4">
                <CsrfField />
                <div><label className="label">Full name</label><input name="name" defaultValue={old.name || ''} className="input" required /></div>
                <div><label className="label">Mobile number *</label><input name="phone" defaultValue={old.phone || ''} placeholder="01XXXXXXXXX" className="input" required /></div>
                <div><label className="label">Email <span className="text-ink-700/40 font-normal">(optional)</span></label><input type="email" name="email" defaultValue={old.email || ''} className="input" /></div>
                <div><label className="label">Password</label><input type="password" name="password" className="input" required /></div>
                <div><label className="label">Confirm password</label><input type="password" name="password_confirmation" className="input" required /></div>
                <button className="btn-primary w-full">Register</button>
            </form>
            <p className="text-center text-sm mt-4">Already have an account? <a href={urls.login} className="text-gold-700 hover:underline">Log in</a></p>
        </AuthCard>
    );
}

Register.layout = (page) => <Layout>{page}</Layout>;
