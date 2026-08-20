import Layout from '../Shared/Chrome/Layout';

// Admin-editable legal pages (privacy / terms / refund). The body is
// admin-authored HTML — the same trust boundary as Blade's {!! !!}.
export default function Legal({ title, body }) {
    return (
        <div className="mx-auto max-w-3xl px-4 py-12">
            <h1 className="font-display text-3xl font-semibold mb-6">{title}</h1>
            <div
                className="prose prose-sm sm:prose max-w-none text-ink-700/85 prose-headings:font-display prose-headings:text-ink-900 prose-a:text-gold-700"
                dangerouslySetInnerHTML={{ __html: body }}
            />
        </div>
    );
}

Legal.layout = (page) => <Layout>{page}</Layout>;
