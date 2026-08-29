import { Fragment } from 'react';

// Light-markdown renderer for admin-typed copy (product descriptions, the
// care/returns accordions). Supports exactly what the description textarea
// documents — "## " headings, "- " bullets, **bold**, *italic*, and a line
// wrapped in quotes as a pull-quote — and nothing else. Plain text stays
// plain: no HTML ever reaches the DOM unescaped, so this is safe on content
// that predates the format.
//
// Everything is parsed into React elements (never dangerouslySetInnerHTML),
// and unknown syntax falls through as literal text.

/** **bold**, *italic* and _italic_ inside one line. */
function inline(text, keyBase) {
    const parts = [];
    // Tokenise on the three markers; longest first so ** wins over *.
    const re = /(\*\*[^*]+\*\*|\*[^*\n]+\*|_[^_\n]+_)/g;
    let last = 0;
    let m;
    let i = 0;

    while ((m = re.exec(text)) !== null) {
        if (m.index > last) parts.push(text.slice(last, m.index));
        const tok = m[0];
        if (tok.startsWith('**')) {
            parts.push(<strong key={`${keyBase}-b${i}`} className="font-semibold">{tok.slice(2, -2)}</strong>);
        } else {
            parts.push(<em key={`${keyBase}-i${i}`}>{tok.slice(1, -1)}</em>);
        }
        last = m.index + tok.length;
        i++;
    }
    if (last < text.length) parts.push(text.slice(last));

    return parts.length ? parts : [text];
}

const isQuote = (line) =>
    (line.startsWith('"') && line.endsWith('"') && line.length > 2)
    || (line.startsWith('“') && line.endsWith('”'));

export default function RichText({ text, className = '' }) {
    if (!text) return null;

    const lines = String(text).replace(/\r\n?/g, '\n').split('\n');
    const blocks = [];
    let list = null;
    let para = [];

    const flushPara = () => {
        if (para.length) {
            blocks.push({ type: 'p', lines: para });
            para = [];
        }
    };
    const flushList = () => {
        if (list) {
            blocks.push({ type: 'ul', items: list });
            list = null;
        }
    };

    for (const raw of lines) {
        const line = raw.trim();
        if (!line) {
            flushPara();
            flushList();
        } else if (line.startsWith('## ')) {
            flushPara();
            flushList();
            blocks.push({ type: 'h', text: line.slice(3).trim() });
        } else if (line.startsWith('- ') || line.startsWith('• ')) {
            flushPara();
            (list ??= []).push(line.slice(2).trim());
        } else if (isQuote(line)) {
            flushPara();
            flushList();
            blocks.push({ type: 'q', text: line });
        } else {
            flushList();
            para.push(line);
        }
    }
    flushPara();
    flushList();

    return (
        <div className={className}>
            {blocks.map((b, i) => {
                if (b.type === 'h') {
                    return <h3 key={i} className="font-display text-xl sm:text-2xl font-medium mt-7 first:mt-0 mb-3">{b.text}</h3>;
                }
                if (b.type === 'q') {
                    return <p key={i} className="italic text-ink-700/75 leading-relaxed mb-4">{inline(b.text, i)}</p>;
                }
                if (b.type === 'ul') {
                    return (
                        <ul key={i} className="list-disc pl-5 space-y-2 mb-4 leading-relaxed">
                            {b.items.map((item, j) => <li key={j}>{inline(item, `${i}-${j}`)}</li>)}
                        </ul>
                    );
                }

                return (
                    <p key={i} className="leading-relaxed mb-4">
                        {b.lines.map((l, j) => (
                            <Fragment key={j}>
                                {j > 0 && <br />}
                                {inline(l, `${i}-${j}`)}
                            </Fragment>
                        ))}
                    </p>
                );
            })}
        </div>
    );
}
