import Layout from '../Shared/Chrome/Layout';
import LookViewer from '../Shared/LookViewer';
import SmartLink from '../Shared/SmartLink';
import { MoreTools, PageHead, shell } from '../Shared/StyleKit';

/* Shop the look (Nov 2026): every active look, photo beside its pieces. */
export default function Looks({ looks }) {
    return (
        <div className={`${shell} pb-10`}>
            <PageHead
                eyebrow="Styled by us"
                title="Shop the look"
                subtitle="Tap a number on the photo to see the piece — or put the whole look in your bag in one go."
            />

            {looks.length === 0 ? (
                <div className="rounded-[28px] border border-ink-900/[0.08] bg-mist-50 p-10 text-center">
                    <p className="text-ink-800">New looks are on their way.</p>
                    <SmartLink href="/shop" className="btn-primary inline-flex mt-6">Browse the collection</SmartLink>
                </div>
            ) : (
                <div className="grid gap-16 lg:gap-24">
                    {looks.map((look) => <LookViewer key={look.id} look={look} />)}
                </div>
            )}

            <MoreTools current="looks" />
        </div>
    );
}

Looks.layout = (page) => <Layout>{page}</Layout>;
