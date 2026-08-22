import { Link } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import Pagination from '../../Shared/Pagination';

const statusClass = (st) =>
    st === 'approved' ? 'bg-success-100 text-success-700'
        : st === 'rejected' ? 'bg-danger-100 text-danger-700'
            : 'bg-warning-100 text-warning-700';

export default function Reviews({ reviews }) {
    return (
        <AccountShell>
            <h1 className="font-display text-2xl font-semibold mb-6">My reviews</h1>

            {reviews.data.length === 0 && (
                <div className="card p-6 text-center text-sm text-ink-700/60">
                    You haven't written any reviews yet. Reviews you leave on products will appear here.
                </div>
            )}
            {reviews.data.map((review, i) => (
                <div key={i} className="card p-4 mb-3">
                    <div className="flex items-center justify-between gap-3">
                        {review.product ? (
                            <Link href={review.product.url} className="font-medium text-gold-700 hover:underline truncate">{review.product.name}</Link>
                        ) : (
                            <span className="font-medium text-ink-700/60">Product removed</span>
                        )}
                        <span className={`badge shrink-0 capitalize ${statusClass(review.status)}`}>
                            {review.status === 'pending' ? 'Pending approval' : review.status}
                        </span>
                    </div>
                    <div className="text-gold-500 text-sm mt-1">
                        {'★'.repeat(review.rating)}<span className="text-ink-200">{'★'.repeat(5 - review.rating)}</span>
                    </div>
                    {review.title && <p className="font-medium text-sm mt-1">{review.title}</p>}
                    {review.body && <p className="text-sm text-ink-700/80 mt-0.5">{review.body}</p>}
                    <p className="text-xs text-ink-700/40 mt-1">{review.date}</p>
                </div>
            ))}

            <Pagination links={reviews.links} />
        </AccountShell>
    );
}

Reviews.layout = (page) => <Layout>{page}</Layout>;
