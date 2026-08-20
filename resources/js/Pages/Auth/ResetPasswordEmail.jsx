import Layout from '../../Shared/Chrome/Layout';
import AuthCard, { CsrfField } from '../../Shared/AuthCard';

export default function ResetPasswordEmail({ token, email }) {
    return (
        <AuthCard title="Choose a new password">
            <form action="/password/reset-email" method="POST" className="mt-6 space-y-4">
                <CsrfField />
                <input type="hidden" name="token" value={token} />
                <div><label className="label">Email</label><input type="email" name="email" defaultValue={email} className="input" readOnly /></div>
                <div><label className="label">New password</label><input type="password" name="password" className="input" required /></div>
                <div><label className="label">Confirm password</label><input type="password" name="password_confirmation" className="input" required /></div>
                <button className="btn-primary w-full">Update password</button>
            </form>
        </AuthCard>
    );
}

ResetPasswordEmail.layout = (page) => <Layout>{page}</Layout>;
