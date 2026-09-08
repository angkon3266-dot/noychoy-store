// The handful of storefront strings that greet, nudge and reward — in Bangla
// for visitors who chose it (or wrote to the assistant in it). Everything
// else on the site stays English; this is the layer to grow when more of it
// should follow. Reward labels arrive from the server in English and are
// translated by pattern so a new rung needs no new key.
const en = {
    'strip.badge': 'Rewards',
    'strip.first': 'Add {more} {pieces} to unlock {reward}',
    'strip.next': '{unlocked} unlocked — add {more} more for {reward}',
    'strip.all': 'All {n} rewards unlocked{saved}',
    'strip.gift': 'A free gift is waiting — pick one in your cart',
    'strip.saved': ' — {saved} saved',
    'piece': 'piece',
    'pieces': 'pieces',
    'header.join': 'Join · {pct}% off',
    'minicart.save': 'Members save {pct}% on this cart',
    'minicart.points': ' and earn {points} points per ৳1,000',
    'minicart.join': 'join free',
    'cart.join': 'Join free and save {saving} on this order — members get {pct}% off every piece',
    'cart.points': ' and earn {points} points per ৳1,000',
    'band.eyebrow.guest': 'Free membership',
    'band.eyebrow.member': 'Your membership',
    'band.title': 'Members save',
    'band.title.tail': 'on every piece',
    'band.title.nopct': 'Members earn on every piece',
    'band.cta.guest': 'Join free',
    'band.cta.member': 'See my points',
    'footer.join': 'Join free — members save {pct}%',
    'footer.account': 'My account & points',
    'footer.lang': 'Language',
};

const bn = {
    'strip.badge': 'রিওয়ার্ড',
    'strip.first': 'আর {more}টি যোগ করলেই {reward}',
    'strip.next': '{unlocked} পেয়েছেন — আর {more}টি যোগ করলে {reward}',
    'strip.all': '{n}টি রিওয়ার্ডই আনলক{saved}',
    'strip.gift': 'একটি ফ্রি গিফট অপেক্ষা করছে — কার্টে বেছে নিন',
    'strip.saved': ' — {saved} সাশ্রয়',
    'piece': 'টি',
    'pieces': 'টি',
    'header.join': 'জয়েন · {pct}% ছাড়',
    'minicart.save': 'মেম্বাররা এই কার্টে {pct}% ছাড় পান',
    'minicart.points': ' আর প্রতি ৳1,000 এ {points} পয়েন্ট',
    'minicart.join': 'ফ্রি জয়েন করুন',
    'cart.join': 'ফ্রি জয়েন করে এই অর্ডারে {saving} সাশ্রয় করুন — মেম্বাররা প্রতিটি গহনায় {pct}% ছাড় পান',
    'cart.points': ' আর প্রতি ৳1,000 এ {points} পয়েন্ট',
    'band.eyebrow.guest': 'ফ্রি মেম্বারশিপ',
    'band.eyebrow.member': 'আপনার মেম্বারশিপ',
    'band.title': 'মেম্বাররা পান',
    'band.title.tail': 'ছাড়, প্রতিটি গহনায়',
    'band.title.nopct': 'মেম্বাররা প্রতিটি গহনায় পয়েন্ট পান',
    'band.cta.guest': 'ফ্রি জয়েন করুন',
    'band.cta.member': 'আমার পয়েন্ট দেখুন',
    'footer.join': 'ফ্রি জয়েন করুন — মেম্বাররা {pct}% ছাড় পান',
    'footer.account': 'আমার অ্যাকাউন্ট ও পয়েন্ট',
    'footer.lang': 'ভাষা',
};

export function t(lang, key, vars = {}) {
    const table = lang === 'bn' ? bn : en;
    const s = table[key] ?? en[key] ?? key;

    return s.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
}

// "Free delivery" → "ফ্রি ডেলিভারি", "৳50 off" → "৳50 ছাড়", "2% off" → "2% ছাড়".
// The bare forms are the stepper's own captions ("Delivery", "Gift"), which
// sit under an icon that already says which reward it is — so they translate
// to the shortest word that fits a 50px cell.
export function rewardLabel(lang, label) {
    if (lang !== 'bn' || !label) return label;
    if (/^free delivery/i.test(label)) return 'ফ্রি ডেলিভারি';
    if (/^free gift/i.test(label)) return 'ফ্রি গিফট';
    if (/^delivery$/i.test(label)) return 'ফ্রি';
    if (/^gift$/i.test(label)) return 'গিফট';

    return label.replace(/\s+off$/i, ' ছাড়');
}

// Lower-case a reward for mid-sentence use in English; Bangla needs nothing.
export function rewardPhrase(lang, label) {
    if (lang === 'bn') return rewardLabel(lang, label);
    if (/^free gift/i.test(label)) return 'a free gift';

    return label.charAt(0).toLowerCase() + label.slice(1);
}
