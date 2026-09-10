// The handful of storefront strings that greet, nudge and reward — in Bangla
// for visitors who chose it (or wrote to the assistant in it). Everything
// else on the site stays English; this is the layer to grow when more of it
// should follow.
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
};

export function t(lang, key, vars = {}) {
    const table = lang === 'bn' ? bn : en;
    const s = table[key] ?? en[key] ?? key;

    return s.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
}
