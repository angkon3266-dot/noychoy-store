<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The trust badges went Bangla on 2026-09-10 and lost the 7-day badge, but
 * the live store has the old English defaults saved in the settings table
 * from the first time the Appearance form was submitted — and the stored
 * value wins, so changing config/theme.php alone leaves the storefront
 * exactly as it was.
 *
 * A row whose title is one of the retired English defaults is swapped for
 * the matching Bangla row (title and text; the stored icon is kept). The
 * 7-day badge is dropped at the owner's request to keep the list short — the
 * refund policy page still carries the 7-day window, this strip just no longer
 * repeats it.
 * Anything the owner wrote themselves is left exactly as it is.
 */
return new class extends Migration
{
    /**
     * Retired default title, normalised => the row that replaces it.
     *
     * @var array<string, array{icon: string, title: string, text: string}>
     */
    private array $map = [
        'cash on delivery' => ['icon' => 'cash', 'title' => 'ক্যাশ অন ডেলিভারি', 'text' => 'পণ্য হাতে পেয়ে টাকা দিন'],
        'fast delivery across bangladesh' => ['icon' => 'truck', 'title' => 'সারা বাংলাদেশে দ্রুত ডেলিভারি', 'text' => ''],
        'fair pricing' => ['icon' => 'tag', 'title' => 'ন্যায্য দাম', 'text' => 'কোনো বাড়তি মার্কআপ নেই'],
        'authentic quality, guaranteed' => ['icon' => 'shieldCheck', 'title' => 'অথেনটিক কোয়ালিটি গ্যারান্টি', 'text' => 'প্রতিটি পিস হাতে চেক করে পাঠানো হয়'],
    ];

    public function up(): void
    {
        $theme = Setting::get('theme');

        if (! is_array($theme) || ! is_array($theme['trust_badges'] ?? null)) {
            return;
        }

        $changed = false;
        $kept = [];

        foreach ($theme['trust_badges'] as $row) {
            $title = is_array($row) ? $this->normalise($row['title'] ?? '') : null;

            if ($title === '7 days to change your mind') {
                $changed = true;
                continue;
            }

            if ($title !== null && isset($this->map[$title])) {
                $new = $this->map[$title];
                $row['title'] = $new['title'];
                $row['text'] = $new['text'];
                $row['icon'] = filled($row['icon'] ?? null) ? $row['icon'] : $new['icon'];
                $changed = true;
            }

            $kept[] = $row;
        }

        if ($changed) {
            $theme['trust_badges'] = $kept;
            Setting::put('theme', $theme);
        }
    }

    /** Case, edge whitespace and doubled spaces are typing noise, not a different badge. */
    private function normalise(mixed $title): string
    {
        return preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $title)));
    }

    public function down(): void
    {
        // One-way on purpose: the English wording was retired, not lost.
    }
};
