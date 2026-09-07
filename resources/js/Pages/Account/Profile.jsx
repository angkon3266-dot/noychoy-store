import { useForm, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import DateField from '../../Shared/DateField';

export default function Profile({ profile, genders, languages = {}, reminderDays = 10 }) {
    const { props } = usePage();
    const errors = props.errors || {};

    const info = useForm({
        name: profile.name || '',
        phone: profile.phone || '',
        email: profile.email || '',
        gender: profile.gender || '',
        locale: profile.locale || '',
        birthday_day: profile.birthday_day || '',
        birthday_month: profile.birthday_month || '',
        anniversary_day: profile.anniversary_day || '',
        anniversary_month: profile.anniversary_month || '',
    });

    const pass = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const err = (k) => errors[k]?.[0];

    return (
        <AccountShell>
            <h1 className="font-display text-2xl font-semibold mb-6">Profile &amp; security</h1>

            <div className="card p-5 mb-6">
                <h2 className="font-semibold mb-4">Account information</h2>
                <form
                    onSubmit={(e) => { e.preventDefault(); info.patch('/account/profile', { preserveScroll: true }); }}
                    className="space-y-4"
                >
                    <div>
                        <label className="label">Name<input value={info.data.name} onChange={(e) => info.setData('name', e.target.value)} className="input" required /></label>
                        {err('name') && <p className="text-xs text-danger-600 mt-1">{err('name')}</p>}
                    </div>
                    <div>
                        <label className="label">Phone<input value={info.data.phone} onChange={(e) => info.setData('phone', e.target.value)} className="input" required /></label>
                        {err('phone') && <p className="text-xs text-danger-600 mt-1">{err('phone')}</p>}
                    </div>
                    <div>
                        <label className="label">Email<input type="email" value={info.data.email} onChange={(e) => info.setData('email', e.target.value)} className="input" placeholder="you@example.com" /></label>
                        {err('email') && <p className="text-xs text-danger-600 mt-1">{err('email')}</p>}
                    </div>
                    <div>
                        <label className="label">Gender (optional)<select value={info.data.gender} onChange={(e) => info.setData('gender', e.target.value)} className="input">
                            <option value="">Prefer not to say</option>
                            {Object.entries(genders).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                        </select></label>
                    </div>
                    <div>
                        <label className="label">Language / ভাষা<select value={info.data.locale} onChange={(e) => info.setData('locale', e.target.value)} className="input">
                            <option value="">Automatic</option>
                            {Object.entries(languages).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                        </select></label>
                        <p className="text-xs text-ink-700/60 mt-1">Greetings, reminders and the assistant use this.</p>
                    </div>

                    <div className="border-t border-ink-100 pt-4">
                        <h3 className="text-sm font-semibold">Special dates <span className="font-normal text-ink-700/60">(optional)</span></h3>
                        <p className="text-xs text-ink-700/60 mb-3">We'll send a little reminder {reminderDays} days before with gift ideas, and a wish on the day.</p>
                        <div className="grid sm:grid-cols-2 gap-4">
                            <DateField label="Birthday" day={info.data.birthday_day} month={info.data.birthday_month}
                                onDay={(v) => info.setData('birthday_day', v)} onMonth={(v) => info.setData('birthday_month', v)} />
                            <DateField label="Anniversary" day={info.data.anniversary_day} month={info.data.anniversary_month}
                                onDay={(v) => info.setData('anniversary_day', v)} onMonth={(v) => info.setData('anniversary_month', v)} />
                        </div>
                        {(err('birthday_day') || err('birthday_month') || err('anniversary_day') || err('anniversary_month')) && (
                            <p className="text-xs text-danger-600 mt-1">Please pick both a day and a month.</p>
                        )}
                    </div>
                    <div className="flex justify-end"><button className="btn-primary" disabled={info.processing}>Save changes</button></div>
                </form>
            </div>

            <div className="card p-5">
                <h2 className="font-semibold mb-1">{profile.hasPassword ? 'Change password' : 'Set a password'}</h2>
                {!profile.hasPassword && (
                    <p className="text-xs text-ink-700/70 mb-4">You signed in with Google. Set a password to also log in with your phone/email.</p>
                )}
                <form
                    onSubmit={(e) => { e.preventDefault(); pass.patch('/account/password', { preserveScroll: true, onSuccess: () => pass.reset() }); }}
                    className="space-y-4 mt-3"
                >
                    {profile.hasPassword && (
                        <div>
                            <label className="label">Current password<input type="password" value={pass.data.current_password} onChange={(e) => pass.setData('current_password', e.target.value)} className="input" required /></label>
                            {err('current_password') && <p className="text-xs text-danger-600 mt-1">{err('current_password')}</p>}
                        </div>
                    )}
                    <div>
                        <label className="label">New password<input type="password" value={pass.data.password} onChange={(e) => pass.setData('password', e.target.value)} className="input" required minLength={8} /></label>
                        {err('password') && <p className="text-xs text-danger-600 mt-1">{err('password')}</p>}
                    </div>
                    <div>
                        <label className="label">Confirm new password<input type="password" value={pass.data.password_confirmation} onChange={(e) => pass.setData('password_confirmation', e.target.value)} className="input" required minLength={8} /></label>
                    </div>
                    <div className="flex justify-end"><button className="btn-primary" disabled={pass.processing}>Update password</button></div>
                </form>
            </div>
        </AccountShell>
    );
}

Profile.layout = (page) => <Layout>{page}</Layout>;
