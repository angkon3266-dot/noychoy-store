import { Link, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import Pagination from '../../Shared/Pagination';
import SmartLink from '../../Shared/SmartLink';

export default function Orders({ orders }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    return (
        <AccountShell wide>
            <h1 className="font-display text-2xl font-semibold mb-6">My orders</h1>

            {orders.data.length === 0 ? (
                <div className="card p-8 text-center text-ink-700/60">
                    No orders yet. <SmartLink href={urls.shop || '/shop'} className="text-gold-700 hover:underline">Start shopping</SmartLink>
                </div>
            ) : (
                <>
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-gold-100/60 text-left text-xs uppercase tracking-wide text-ink-700/60">
                                    <tr><th className="px-4 py-3">Order</th><th className="px-4 py-3">Date</th><th className="px-4 py-3">Total</th><th className="px-4 py-3">Status</th><th></th></tr>
                                </thead>
                                <tbody className="divide-y divide-ink-100">
                                    {orders.data.map((order) => (
                                        <tr key={order.number}>
                                            <td className="px-4 py-3 font-medium">{order.number}</td>
                                            <td className="px-4 py-3 text-ink-700/70">{order.date}</td>
                                            <td className="px-4 py-3">{order.totalText}</td>
                                            <td className="px-4 py-3"><span className="badge bg-gold-100 text-gold-800 capitalize">{order.status}</span></td>
                                            <td className="px-4 py-3 text-right"><Link href={order.url} className="text-gold-700 hover:underline">View</Link></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div className="mt-6"><Pagination links={orders.links} /></div>
                </>
            )}
        </AccountShell>
    );
}

Orders.layout = (page) => <Layout>{page}</Layout>;
