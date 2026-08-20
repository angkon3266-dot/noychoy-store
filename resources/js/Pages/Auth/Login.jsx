import { usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { CsrfField } from '../../Shared/AuthCard';

export default function Login({ old }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    return (
        <AuthCard title="Welcome back" subtitle="Log in to your account">
            <form action={urls.login} method="POST" className="mt-6 space-y-4">
                <CsrfField />
                <div><label className="label">Mobile number</label><input name="phone" defaultValue={old.phone || ''} className="input" required /></div>
                <div><label className="label">Password</label><input type="password" name="password" className="input" required /></div>
                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" /> Remember me</label>
                    <a href="/password/forgot" className="text-sm text-gold-700 hover:underline">Forgot password?</a>
                </div>
                <button className="btn-primary w-full">Log in</button>
            </form>
            <p className="text-center text-sm mt-4">No account? <a href={urls.register} className="text-gold-700 hover:underline">Register</a></p>
        </AuthCard>
    );
}

Login.layout = (page) => <Layout>{page}</Layout>;
