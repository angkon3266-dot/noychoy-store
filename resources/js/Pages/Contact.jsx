import { useForm, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';

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
                        {details.phone && <li className="flex items-center gap-2">📞 <a href={`tel:${details.phone}`} className="hover:text-gold-700">{details.phone}</a></li>}
                        {details.email && <li className="flex items-center gap-2">✉️ <a href={`mailto:${details.email}`} className="hover:text-gold-700">{details.email}</a></li>}
                        {details.whatsapp && <li className="flex items-center gap-2">💬 <a href={`https://wa.me/${details.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener" className="hover:text-gold-700">WhatsApp us</a></li>}
                        {details.address && <li className="flex items-start gap-2">📍 <span>{details.address}</span></li>}
                    </ul>
                </div>

                <div className="card p-6">
                    {props.flash?.success && (
                        <div className="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm mb-4">{props.flash.success}</div>
                    )}
                    {firstError && (
                        <div className="rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm mb-4">{firstError}</div>
                    )}
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid sm:grid-cols-2 gap-3">
                            <input {...field('name', { placeholder: 'Your name *', required: true })} className="input" />
                            <input {...field('phone', { placeholder: 'Phone' })} className="input" />
                        </div>
                        <input {...field('email', { type: 'email', placeholder: 'Email (optional)' })} className="input" />
                        <input {...field('subject', { placeholder: 'Subject' })} className="input" />
                        <textarea {...field('message', { rows: 5, placeholder: 'How can we help? *', required: true })} className="input" />
                        <button className="btn-primary w-full" disabled={processing}>{processing ? 'Sending…' : 'Send message'}</button>
                    </form>
                </div>
            </div>
        </div>
    );
}

Contact.layout = (page) => <Layout>{page}</Layout>;
