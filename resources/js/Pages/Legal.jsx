import Layout from '../Shared/Chrome/Layout';

// Admin-editable legal pages (privacy / terms / refund). The body is
// admin-authored HTML — the same trust boundary as Blade's {!! !!}.
export default function Legal({ title, body }) {
    return (
        <div className="mx-auto max-w-3xl px-4 py-12">
            <h1 className="font-display text-3xl font-semibold mb-6">{title}</h1>
            {/* `.rich-text`, not `prose`: @tailwindcss/typography is not
                installed, so the prose classes that used to be here emitted no
                CSS at all and these pages rendered as one wall of text. */}
            <div className="rich-text" dangerouslySetInnerHTML={{ __html: body }} />
        </div>
    );
}

Legal.layout = (page) => <Layout>{page}</Layout>;
