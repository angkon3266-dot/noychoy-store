import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import Icon from '../../Shared/Icons';

const blank = (defaults) => ({
    label: '', name: defaults.name || '', phone: defaults.phone || '',
    address: '', area: '', city: '', district: '',
    is_inside_dhaka: false, is_default: false,
});

export default function Addresses({ addresses, storeUrl, defaults }) {
    const [editing, setEditing] = useState(null);   // address id being edited
    const [addOpen, setAddOpen] = useState(addresses.length === 0);

    return (
        <AccountShell>
            <div className="flex items-center justify-between mb-6">
                <h1 className="font-display text-2xl font-semibold">My addresses</h1>
            </div>

            <div className="space-y-3 mb-6">
                {addresses.length === 0 && <p className="text-sm text-ink-700/60">No saved addresses yet.</p>}
                {addresses.map((a) => (
                    <div key={a.id} className="card p-4">
                        {editing === a.id ? (
                            <AddressForm
                                initial={a}
                                submitLabel="Save"
                                onCancel={() => setEditing(null)}
                                onSubmit={(data) => router.patch(a.updateUrl, data, { preserveScroll: true, onSuccess: () => setEditing(null) })}
                            />
                        ) : (
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">{a.name}</span>
                                        {a.label && <span className="badge bg-ink-50 text-ink-700">{a.label}</span>}
                                        {a.is_default && <span className="badge bg-gold-100 text-gold-800">Default</span>}
                                    </div>
                                    <p className="text-sm text-ink-700/70">{a.phone}</p>
                                    <p className="text-sm text-ink-700/70">{a.line}</p>
                                </div>
                                <div className="flex flex-col items-end gap-1 shrink-0 text-sm">
                                    <button onClick={() => setEditing(a.id)} className="text-gold-700 hover:underline">Edit</button>
                                    {!a.is_default && (
                                        <>
                                            <button onClick={() => router.post(a.defaultUrl, {}, { preserveScroll: true })} className="text-ink-700/70 hover:underline">Set default</button>
                                            <button
                                                onClick={() => confirm('Remove this address?') && router.delete(a.deleteUrl, { preserveScroll: true })}
                                                className="text-red-600 hover:underline"
                                            >
                                                Delete
                                            </button>
                                        </>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            {/* Add new */}
            <div className="card p-5">
                <button onClick={() => setAddOpen(!addOpen)} type="button" className="flex w-full items-center justify-between font-semibold">
                    + Add a new address
                    <Icon name="chevronDown" className={`w-5 h-5 transition-transform ${addOpen ? 'rotate-180' : ''}`} strokeWidth={2} />
                </button>
                {addOpen && (
                    <div className="mt-4">
                        <AddressForm
                            initial={blank(defaults)}
                            submitLabel="Save address"
                            onSubmit={(data) => router.post(storeUrl, data, { preserveScroll: true })}
                        />
                    </div>
                )}
            </div>
        </AccountShell>
    );
}

function AddressForm({ initial, onSubmit, onCancel = null, submitLabel }) {
    const { props } = usePage();
    const errors = props.errors || {};
    const firstError = Object.values(errors)[0]?.[0];
    const [data, setData] = useState({
        label: initial.label || '',
        name: initial.name || '',
        phone: initial.phone || '',
        address: initial.address || '',
        area: initial.area || '',
        city: initial.city || '',
        district: initial.district || '',
        is_inside_dhaka: !!initial.is_inside_dhaka,
        is_default: !!initial.is_default,
    });
    const set = (k) => (e) => setData({ ...data, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(data); }} className="space-y-3">
            {firstError && <div className="rounded-md bg-red-50 border border-red-200 text-red-700 px-3 py-2 text-sm">{firstError}</div>}
            <div className="grid sm:grid-cols-2 gap-3">
                <div><label className="label text-xs">Label (optional)</label><input value={data.label} onChange={set('label')} className="input" placeholder="Home / Office" /></div>
                <div><label className="label text-xs">Full name</label><input value={data.name} onChange={set('name')} className="input" required /></div>
            </div>
            <div><label className="label text-xs">Phone</label><input value={data.phone} onChange={set('phone')} className="input" required /></div>
            <div><label className="label text-xs">Full address</label><textarea value={data.address} onChange={set('address')} rows={2} className="input" required placeholder="House, road, block…" /></div>
            <div className="grid sm:grid-cols-3 gap-3">
                <div><label className="label text-xs">Area</label><input value={data.area} onChange={set('area')} className="input" /></div>
                <div><label className="label text-xs">City</label><input value={data.city} onChange={set('city')} className="input" /></div>
                <div><label className="label text-xs">District</label><input value={data.district} onChange={set('district')} className="input" /></div>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={data.is_inside_dhaka} onChange={set('is_inside_dhaka')} /> Inside Dhaka</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={data.is_default} onChange={set('is_default')} /> Set as default address</label>
            <div className="flex justify-end gap-2">
                {onCancel && <button type="button" onClick={onCancel} className="btn-outline text-sm py-1.5">Cancel</button>}
                <button className="btn-primary text-sm py-1.5">{submitLabel}</button>
            </div>
        </form>
    );
}

Addresses.layout = (page) => <Layout>{page}</Layout>;
