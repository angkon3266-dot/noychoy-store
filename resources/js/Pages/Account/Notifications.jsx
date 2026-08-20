import Layout from '../../Shared/Chrome/Layout';
import Pagination from '../../Shared/Pagination';

export default function Notifications({ items }) {
    return (
        <div className="mx-auto max-w-3xl px-4 py-10">
            <h1 className="font-display text-3xl font-semibold mb-6">Notifications</h1>

            <div className="space-y-3">
                {items.data.length === 0 && <div className="card p-10 text-center text-ink-700/50">No notifications yet.</div>}
                {items.data.map((n, i) => (
                    <a
                        key={i}
                        href={n.url || '#'}
                        className={`card p-4 flex items-start gap-3 hover:border-gold-300 transition ${n.url ? '' : 'pointer-events-none'}`}
                    >
                        <span className="text-2xl shrink-0">{n.icon}</span>
                        <div className="min-w-0">
                            <p className="font-medium">{n.title}</p>
                            {n.body && <p className="text-sm text-ink-700/70 mt-0.5">{n.body}</p>}
                            <p className="text-xs text-ink-700/40 mt-1">{n.time}</p>
                            {n.url && n.cta && <span className="inline-block mt-2 text-sm text-gold-700 font-medium">{n.cta} →</span>}
                        </div>
                    </a>
                ))}
            </div>

            <div className="mt-6"><Pagination links={items.links} /></div>
        </div>
    );
}

Notifications.layout = (page) => <Layout>{page}</Layout>;
