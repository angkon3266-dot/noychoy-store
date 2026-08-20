import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { CsrfField } from '../../Shared/AuthCard';

export default function ResetPassword({ phone }) {
    return (
        <AuthCard title="Enter your code" subtitle="Check your SMS for the 6-digit code, then set a new password.">
            <form action="/password/reset" method="POST" className="mt-6 space-y-4">
                <CsrfField />
                <input type="hidden" name="phone" value={phone} />
                <div><label className="label">6-digit code</label><input name="otp" inputMode="numeric" maxLength={6} className="input tracking-widest text-center text-lg" placeholder="••••••" required /></div>
                <div><label className="label">New password</label><input type="password" name="password" className="input" required minLength={6} /></div>
                <div><label className="label">Confirm new password</label><input type="password" name="password_confirmation" className="input" required /></div>
                <button className="btn-primary w-full">Reset password</button>
            </form>
            <p className="text-center text-sm mt-4"><a href="/password/forgot" className="text-gold-700 hover:underline">Didn't get a code? Try again</a></p>
        </AuthCard>
    );
}

ResetPassword.layout = (page) => <Layout>{page}</Layout>;
