// Day + month pickers for one special date (birthday, anniversary). No year:
// nobody wants to type it at a jewelry store, and a wish needs neither.
export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export default function DateField({ label, hint, day, month, onDay, onMonth, compact = false }) {
    return (
        <div>
            <span className={compact ? 'text-xs font-medium text-ink-800 block mb-1' : 'label'}>{label}</span>
            <div className="flex gap-2">
                <select value={day} onChange={(e) => onDay(e.target.value)} className={`input ${compact ? 'py-1.5 text-sm' : ''} w-24`} aria-label={`${label} day`}>
                    <option value="">Day</option>
                    {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
                <select value={month} onChange={(e) => onMonth(e.target.value)} className={`input ${compact ? 'py-1.5 text-sm' : ''} flex-1`} aria-label={`${label} month`}>
                    <option value="">Month</option>
                    {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                </select>
            </div>
            {hint && <p className="text-xs text-ink-700/60 mt-1">{hint}</p>}
        </div>
    );
}
