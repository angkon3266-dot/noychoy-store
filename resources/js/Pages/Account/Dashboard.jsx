import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Layout from '../../Shared/Chrome/Layout';
import AccountShell from '../../Shared/AccountShell';
import Icon from '../../Shared/Icons';
import SmartLink from '../../Shared/SmartLink';

export default function Dashboard({ customer, stats, member, liveOffers, loyalty, defaultAddress, orders }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};

    return (
        <AccountShell wide>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <p className="text-sm text-ink-700/60">Welcome back,</p>
                    <h1 className="font-display text-2xl md:text-3xl font-semibold">{customer.name}</h1>
                </div>
            </div>

            {member && (
                <div className="mb-6 rounded-xl border-2 border-gold-300 bg-gradient-to-r from-gold-100/70 to-white p-4 flex items-center gap-4">
                    <Icon name="medal" className="w-8 h-8 text-gold-700 shrink-0" />
                    <div>
                        <p className="font-semibold text-gold-800">You're a member — {member.percent}% off on every order.</p>
                        <p className="text-sm text-ink-700/70">You've saved <strong className="text-gold-700">{member.savedText}</strong> so far as a member. Keep shopping to save more!</p>
                    </div>
                </div>
            )}

            {/* Summary tiles */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
                <Link href="/account/orders" className="card p-4 hover:border-gold-300 transition">
                    <div className="text-2xl font-semibold text-gold-700">{customer.totalOrders}</div>
                    <div className="text-xs text-ink-700/60">Orders</div>
                </Link>
                <div className="card p-4">
                    <div className="text-2xl font-semibold text-gold-700">{customer.totalSpentText}</div>
                    <div className="text-xs text-ink-700/60">Total spent</div>
                </div>
                <Link href="/account/loved" className="card p-4 hover:border-gold-300 transition">
                    <div className="text-2xl font-semibold text-danger-500">{stats.loved}</div>
                    <div className="text-xs text-ink-700/60">Loved items</div>
                </Link>
                <Link href="/account/reviews" className="card p-4 hover:border-gold-300 transition">
                    <div className="text-2xl font-semibold text-gold-700">{stats.reviews}</div>
                    <div className="text-xs text-ink-700/60">Reviews</div>
                </Link>
            </div>

            {/* Exclusive offers */}
            {liveOffers.length > 0 && (
                <div className="card p-5 mb-8 border-gold-300">
                    <h2 className="font-semibold mb-3 flex items-center gap-2"><Icon name="gift" className="w-5 h-5 text-gold-700" />Your exclusive offers <span className="badge bg-gold-600 text-white text-[10px]">{liveOffers.length}</span></h2>
                    <div className="grid sm:grid-cols-2 gap-3">
                        {liveOffers.map((offer, i) => (
                            <div key={i} className="rounded-xl border-2 border-gold-300 bg-gradient-to-r from-gold-100/70 to-white p-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold">{offer.title}</p>
                                        <span className="inline-block badge bg-gold-600 text-white text-[10px] mt-0.5">{offer.reward}</span>
                                        {offer.scope && <span className="inline-block text-[10px] text-ink-700/60 ml-1">· {offer.scope}</span>}
                                        {offer.message && <p className="text-xs text-ink-700/70 italic mt-1">{offer.message}</p>}
                                        <p className="text-xs text-success-700 mt-1 flex items-center gap-1"><Icon name="check" className="w-3.5 h-3.5" />Applied automatically at checkout{offer.until ? ` · until ${offer.until}` : ''}</p>
                                    </div>
                                    {offer.code && <span className="shrink-0 font-mono text-xs rounded-full border border-gold-300 px-2.5 py-1 bg-gold-50">{offer.code}</span>}
                                </div>
                            </div>
                        ))}
                    </div>
                    <SmartLink href={urls.shop || '/shop'} className="btn-primary text-sm mt-4 inline-block">Shop now</SmartLink>
                </div>
            )}

            {loyalty && <Rewards loyalty={loyalty} shopUrl={urls.shop || '/shop'} />}

            {/* Default address */}
            <div className="card p-5 mb-8">
                <div className="flex items-center justify-between mb-2">
                    <h2 className="font-semibold">Default shipping address</h2>
                    <Link href="/account/addresses" className="text-sm text-gold-700 hover:underline">Manage →</Link>
                </div>
                {defaultAddress ? (
                    <>
                        <p className="text-sm text-ink-800">{defaultAddress.name} · {defaultAddress.phone}</p>
                        <p className="text-sm text-ink-700/70">{defaultAddress.line}</p>
                    </>
                ) : (
                    <p className="text-sm text-ink-700/60">No saved address yet. <Link href="/account/addresses" className="text-gold-700 hover:underline">Add one</Link> for faster checkout.</p>
                )}
            </div>

            {/* Recent orders */}
            <div className="flex items-center justify-between mb-4">
                <h2 className="font-display text-xl font-semibold">Recent orders</h2>
                <Link href="/account/orders" className="text-sm text-gold-700 hover:underline">View all →</Link>
            </div>
            {orders.length ? orders.map((order) => (
                <div key={order.number} className="card p-4 mb-3 flex flex-wrap items-center gap-3">
                    <div className="flex-1 min-w-0">
                        <Link href={order.url} className="font-medium text-gold-700 hover:underline">#{order.number}</Link>
                        <span className="text-xs text-ink-700/50 ml-2">{order.date}</span>
                        <div className="text-sm text-ink-700/70">{order.totalText} · <span className="badge bg-gold-100 text-gold-800 capitalize">{order.status}</span></div>
                    </div>
                    <button type="button" onClick={() => router.post(order.reorderUrl)} className="btn-outline text-sm py-1.5">Reorder</button>
                </div>
            )) : (
                <div className="card p-6 text-center text-sm text-ink-700/60">
                    No orders yet. <SmartLink href={urls.shop || '/shop'} className="text-gold-700 hover:underline">Start shopping →</SmartLink>
                </div>
            )}
        </AccountShell>
    );
}

function Rewards({ loyalty, shopUrl }) {
    const [open, setOpen] = useState(true);
    const msDone = loyalty.milestones.filter((m) => m.done).length;
    const msTotal = loyalty.milestones.length;
    const usage = loyalty.memberUsage;

    return (
        <div className="card mb-8 overflow-hidden">
            <button type="button" onClick={() => setOpen(!open)} className="w-full flex items-center justify-between p-5 text-left">
                <span className="flex items-center gap-3">
                    <Icon name="gift" className="w-6 h-6 text-gold-700" />
                    <span>
                        <span className="block font-semibold">Rewards &amp; offers</span>
                        <span className="block text-xs text-ink-700/60">{loyalty.points} points · {loyalty.pointsValueText} value</span>
                    </span>
                </span>
                <Icon name="chevronDown" className={`w-5 h-5 text-ink-700/50 transition ${open ? 'rotate-180' : ''}`} strokeWidth={2} />
            </button>

            {open && (
                <div className="px-5 pb-5 space-y-5">
                    <div className="rounded-xl bg-gold-50 border border-gold-200 p-4 flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <div className="text-3xl font-semibold text-gold-700">{loyalty.points} <span className="text-base font-normal">points</span></div>
                            <div className="text-xs text-ink-700/60">Worth <strong>{loyalty.pointsValueText}</strong> — 100 points = {loyalty.value100Text} to redeem at checkout</div>
                        </div>
                        <SmartLink href={shopUrl} className="btn-primary text-sm py-2">Shop &amp; earn more</SmartLink>
                    </div>

                    {/* Membership tier */}
                    <div className="rounded-xl border border-ink-100 p-4">
                        <div className="flex items-center justify-between">
                            <span className="inline-flex items-center gap-2 font-semibold">
                                <span className="text-lg">{loyalty.tier.emoji}</span>
                                {loyalty.tier.label} member
                            </span>
                            <span className="text-xs text-ink-700/50">{loyalty.tier.lifetime} lifetime pts</span>
                        </div>
                        <p className="text-xs text-ink-700/60 mt-1">{loyalty.tier.perk}</p>
                        {loyalty.tier.next && (
                            <div className="mt-3">
                                <div className="h-2 rounded-full bg-ink-100 overflow-hidden"><div className="h-full bg-gold-600" style={{ width: `${loyalty.tier.next.progress}%` }} /></div>
                                <p className="text-xs text-ink-700/50 mt-1">Earn {loyalty.tier.next.toNext} more points to reach <strong>{loyalty.tier.next.label}</strong> ({loyalty.tier.next.perk}).</p>
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg bg-ink-50 border border-ink-100 p-3 text-xs text-ink-700/80 space-y-1">
                        <p className="flex items-start gap-1.5"><Icon name="bag" className="w-3.5 h-3.5 mt-0.5 shrink-0" /><span>Earn <strong>{loyalty.per1000} points</strong> for every <strong>৳1000</strong> you spend — added once your order is <strong>delivered</strong>. The more you buy, the more you save!</span></p>
                        <p className="flex items-start gap-1.5"><Icon name="pen" className="w-3.5 h-3.5 mt-0.5 shrink-0" /><span>Write a review: <strong>+{loyalty.reviewPoints} points</strong> (+{loyalty.reviewPhotoBonus} with a photo)</span></p>
                        <p className="flex items-start gap-1.5"><Icon name="cash" className="w-3.5 h-3.5 mt-0.5 shrink-0" /><span><strong>100 points = {loyalty.value100Text}</strong> — use them to cut your bill at checkout.</span></p>
                    </div>

                    {usage && (
                        <div className="rounded-xl border border-gold-200 bg-gold-50 p-4">
                            <h3 className="font-semibold text-sm flex items-center gap-2"><Icon name="diamond" className="w-4 h-4" />Your member discount ({usage.percent}% off)</h3>
                            {usage.capped ? (
                                usage.remaining > 0 ? (
                                    <p className="text-xs text-ink-700/70 mt-1"><strong>{usage.remaining} of {usage.max}</strong> uses left this period.{usage.resets ? ` Resets ${usage.resets}.` : ''}</p>
                                ) : (
                                    <p className="text-xs text-ink-700/70 mt-1">You've used all {usage.max} for now.{usage.resets ? ` More unlock ${usage.resets}.` : ''}</p>
                                )
                            ) : (
                                <p className="text-xs text-ink-700/70 mt-1">Applied automatically on every order.</p>
                            )}
                        </div>
                    )}

                    {/* Weekly milestones */}
                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-sm font-semibold">This week's milestones</h3>
                            <span className="text-xs text-ink-700/60">{msDone}/{msTotal} done</span>
                        </div>
                        <div className="h-2 rounded-full bg-ink-100 overflow-hidden mb-3">
                            <div className="h-full bg-gold-600 transition-all" style={{ width: `${msTotal ? Math.round(msDone / msTotal * 100) : 0}%` }} />
                        </div>
                        <ul className="space-y-2">
                            {loyalty.milestones.map((m, i) => (
                                <li key={i} className="flex items-center gap-3 text-sm">
                                    <span className={`w-7 h-7 grid place-items-center rounded-full ${m.done ? 'bg-success-100 text-success-700' : 'bg-ink-100 text-ink-400'}`}>
                                        {m.done ? <Icon name="check" className="w-4 h-4" /> : m.icon}
                                    </span>
                                    <span className={`flex-1 ${m.done ? 'text-ink-700/50 line-through' : ''}`}>{m.label}</span>
                                    <span className="text-xs font-medium text-gold-700">+{m.points}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </div>
    );
}

Dashboard.layout = (page) => <Layout>{page}</Layout>;
