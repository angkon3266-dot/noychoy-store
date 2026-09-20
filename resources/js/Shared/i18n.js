// The handful of storefront strings that greet, nudge and reward — in Bangla
// for visitors who chose it (or wrote to the assistant in it). Everything
// else on the site stays English; this is the layer to grow when more of it
// should follow. Reward labels arrive from the server in English and are
// translated by pattern (rewardLabel below) so a new rung needs no new key.
const en = {
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

    // The reward ladder, told before the cart (owner, 2026-09-17). {nth} is
    // ordinal() below, {saving}/{pay} are money strings, {perks} is one or
    // more rewardPhrase()s joined with " + ".
    'ladder.piece': '{saving} off as your {nth} piece — you pay {pay}',
    'ladder.pieces': '{saving} off these {n} pieces — you pay {pay}',
    'ladder.piece.short': '{saving} off as your {nth} piece',
    'ladder.pieces.short': '{saving} off these {n} pieces',
    'ladder.piece.perk': 'Your {nth} piece unlocks {perks}',
    'ladder.pieces.perk': 'These {n} pieces unlock {perks}',
    'ladder.row.heading': 'Every piece saves more',
    'ladder.row.unlocked': 'unlocked',
    'card.flat': '{off} → {price}',
    'card.flat.from': '{off} → from {price}',
    'fbt.save': 'You save {saving} with piece rewards',
    'fbt.perk': '+ {perk} unlocked',
    'fbt.together': 'Buy them together at a better deal',

    // An offer's deadline, ticking (OfferCountdown). {t} is "2d 04:12:33".
    'offer.ends': 'Ends in {t}',
    'offer.endsSoon': 'Hurry — ends in {t}',
};

const bn = {
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

    'ladder.piece': '{nth} পিসে {saving} ছাড় — আপনি দেবেন {pay}',
    'ladder.pieces': 'এই {n}টি পিসে {saving} ছাড় — আপনি দেবেন {pay}',
    'ladder.piece.short': '{nth} পিসে {saving} ছাড়',
    'ladder.pieces.short': 'এই {n}টি পিসে {saving} ছাড়',
    'ladder.piece.perk': '{nth} পিসে পাবেন {perks}',
    'ladder.pieces.perk': 'এই {n}টি পিসে পাবেন {perks}',
    'ladder.row.heading': 'প্রতি পিসেই রিওয়ার্ড',
    'ladder.row.unlocked': 'আনলক',
    'card.flat': '{off} → {price}',
    'card.flat.from': '{off} → {price} থেকে',
    'fbt.save': 'পিস রিওয়ার্ডে সাশ্রয় {saving}',
    'fbt.perk': '+ {perk} আনলক',
    'fbt.together': 'একসাথে কিনলে আরও ভালো দামে',

    'offer.ends': 'শেষ হতে বাকি {t}',
    'offer.endsSoon': 'তাড়াতাড়ি করুন — বাকি {t}',
};

export function t(lang, key, vars = {}) {
    const table = lang === 'bn' ? bn : en;
    const s = table[key] ?? en[key] ?? key;

    return s.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
}

// "Free delivery" → "ফ্রি ডেলিভারি", "৳50 off" → "৳50 ছাড়", "2% off" → "2% ছাড়".
// The bare forms are the stepper's own captions ("Delivery", "Gift"), which
// sit under an icon that already says which reward it is — so they translate
// to the shortest word that fits a 50px cell. Brought back from before the
// 2026-09-10 de-clutter (30c068c) for the product-page ladder, 2026-09-17.
export function rewardLabel(lang, label) {
    if (lang !== 'bn' || !label) return label;
    if (/^free delivery/i.test(label)) return 'ফ্রি ডেলিভারি';
    if (/^free gift/i.test(label)) return 'ফ্রি গিফট';
    if (/^delivery$/i.test(label)) return 'ফ্রি';
    if (/^gift$/i.test(label)) return 'গিফট';

    return label.replace(/\s+off$/i, ' ছাড়');
}

// Lower-case a reward for mid-sentence use in English ("+ free delivery",
// "+ a free gift"); Bangla has no case, so it only needs the translation.
export function rewardPhrase(lang, label) {
    if (lang === 'bn') return rewardLabel(lang, label);
    if (!label) return label;
    if (/^free gift/i.test(label)) return 'a free gift';

    return label.charAt(0).toLowerCase() + label.slice(1);
}

// The first ten Bangla ordinals are words of their own; past দশম the
// language counts with the -তম suffix on the numeral. The digits stay Latin,
// as they do in every price on the site.
const BN_ORDINALS = ['প্রথম', 'দ্বিতীয়', 'তৃতীয়', 'চতুর্থ', 'পঞ্চম', 'ষষ্ঠ', 'সপ্তম', 'অষ্টম', 'নবম', 'দশম'];

/** "1st", "2nd", "11th", "21st" — or "প্রথম", "দ্বিতীয়", "11তম" in Bangla. */
export function ordinal(lang, n) {
    const i = Math.trunc(Number(n)) || 0;
    if (lang === 'bn') return BN_ORDINALS[i - 1] ?? `${i}তম`;

    const teen = i % 100 >= 11 && i % 100 <= 13;
    const suffix = teen ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[i % 10] ?? 'th');

    return `${i}${suffix}`;
}
