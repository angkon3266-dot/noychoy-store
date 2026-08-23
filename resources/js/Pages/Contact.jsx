import { useForm, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import Icon from '../Shared/Icons';

export default function Contact({ title, intro, details, submitUrl }) {
    const { props } = usePage();
    const errors = props.errors || {};
    const firstError = Object.values(errors)[0]?.[0];
    const { data, setData, post, processing, reset } = useForm({
        name: '', phone: '', email: '', subject: '', message: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(submitUrl, { preserveScroll: true, onSuccess: () => reset() });
    };

    const field = (name, extra = {}) => ({
        value: data[name],
        onChange: (e) => setData(name, e.target.value),
        ...extra,
    });

    return (
        <div className="mx-auto max-w-5xl px-4 py-12">
            <h1 className="font-display text-3xl font-semibold mb-2">{title}</h1>
            <p className="text-ink-700/70 mb-8 max-w-2xl">{intro}</p>

            <div className="grid md:grid-cols-2 gap-8">
                <div className="space-y-4">
                    <h2 className="font-display text-lg font-semibold">Reach us</h2>
                    <ul className="space-y-3 text-sm">
                        {details.phone && <li className="flex items-center gap-2"><Icon name="phone" className="w-4 h-4 shrink-0" /> <a href={`tel:${details.phone}`} className="hover:text-gold-700">{details.phone}</a></li>}
                        {details.email && <li className="flex items-center gap-2"><Icon name="mail" className="w-4 h-4 shrink-0" /> <a href={`mailto:${details.email}`} className="hover:text-gold-700">{details.email}</a></li>}
                        {details.whatsapp && <li className="flex items-center gap-2"><Icon name="chat" className="w-4 h-4 shrink-0" /> <a href={`https://wa.me/${details.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener" className="hover:text-gold-700">WhatsApp us</a></li>}
                        {details.address && <li className="flex items-start gap-2"><Icon name="pin" className="w-4 h-4 shrink-0 mt-0.5" /> <span>{details.address}</span></li>}
                    </ul>
                </div>

                <div className="card p-6">
                    {firstError && (
                        <div className="rounded-md bg-danger-50 border border-danger-200 text-danger-700 px-4 py-2.5 text-sm mb-4">{firstError}</div>
                    )}
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid sm:grid-cols-2 gap-3">
                            <input {...field('name', { placeholder: 'Your name *', required: true })} aria-label="Your name" className="input" />
                            <input {...field('phone', { placeholder: 'Phone' })} aria-label="Phone" className="input" />
                        </div>
                        <input {...field('email', { type: 'email', placeholder: 'Email (optional)' })} aria-label="Email" className="input" />
                        <input {...field('subject', { placeholder: 'Subject' })} aria-label="Subject" className="input" />
                        <textarea {...field('message', { rows: 5, placeholder: 'How can we help? *', required: true })} aria-label="Message" className="input" />
                        <button className="btn-primary w-full" disabled={processing}>{processing ? 'Sending…' : 'Send message'}</button>
                    </form>
                </div>
            </div>
        </div>
    );
}

Contact.layout = (page) => <Layout>{page}</Layout>;
