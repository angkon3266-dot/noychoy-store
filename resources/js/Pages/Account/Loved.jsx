import { usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import Pagination from '../../Shared/Pagination';
import ProductCard from '../../Shared/ProductCard';
import SmartLink from '../../Shared/SmartLink';
import Icon from '../../Shared/Icons';

export default function Loved({ products }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    return (
        <AccountShell wide>
            <h1 className="font-display text-2xl font-semibold mb-6">Loved items</h1>

            {products.data.length === 0 ? (
                <div className="card p-8 text-center text-sm text-ink-700/70">
                    <svg aria-hidden="true" className="w-10 h-10 mx-auto text-ink-200 mb-3" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
                    Nothing loved yet. Tap the <Icon name="heart" className="w-4 h-4 inline -mt-0.5" /> on any product to save it here.
                    <div className="mt-3"><SmartLink href={urls.shop || '/shop'} className="btn-primary inline-block">Browse products</SmartLink></div>
                </div>
            ) : (
                <>
                    <h2 className="sr-only">Saved products</h2>
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        {products.data.map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
                    <div className="mt-6"><Pagination links={products.links} /></div>
                </>
            )}
        </AccountShell>
    );
}

Loved.layout = (page) => <Layout>{page}</Layout>;
