// Courier tracking progress — port of shop/_order-tracking.blade.php.
const STEPS = ['Booked', 'Picked up', 'In transit', 'Delivered'];

export default function TrackingProgress({ tracking }) {
    if (!tracking) return null;

    return (
        <div className="mt-6 border-t border-ink-100 pt-5">
            <div className="flex items-center justify-between mb-4">
                <h2 className="font-medium">Courier tracking</h2>
                <span className={`badge ${tracking.tone_class}`}>{tracking.label}</span>
            </div>
            <div className="flex items-center">
                {STEPS.map((s, i) => (
                    <div key={s} className={`flex items-center ${i === STEPS.length - 1 ? '' : 'flex-1'}`}>
                        <div className="flex flex-col items-center">
                            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs ${i <= tracking.step ? 'bg-gold-600 text-white' : 'bg-ink-100 text-ink-700'}`}>
                                {i < tracking.step ? (
                                    <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                ) : (
                                    i + 1
                                )}
                            </div>
                            <span className={`mt-1 text-[10px] text-center ${i <= tracking.step ? 'text-ink-800' : 'text-ink-700'}`}>{s}</span>
                        </div>
                        {i < STEPS.length - 1 && <div className={`flex-1 h-0.5 mx-1 -mt-4 ${i < tracking.step ? 'bg-gold-600' : 'bg-ink-100'}`}></div>}
                    </div>
                ))}
            </div>
            {tracking.tracking_code && (
                <p className="mt-4 text-sm text-ink-700/70">Tracking code: <strong className="text-ink-900">{tracking.tracking_code}</strong> · via Steadfast</p>
            )}
        </div>
    );
}
