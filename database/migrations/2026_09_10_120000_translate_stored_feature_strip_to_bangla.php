<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Companion to the trust-badge migration. The homepage feature strip carries
 * the same promises as the trust badges — delivery, returns, quality, support
 * — and it sits higher on the page, so leaving it in English undid most of the
 * point of translating the badges.
 *
 * The stored value wins over config/home.php, and the live store saved the
 * English defaults the first time the Appearance form was submitted, so the
 * config change alone would not reach the storefront.
 *
 * A row whose title is one of the retired English defaults is swapped for the
 * Bangla one. Anything the owner wrote themselves is left exactly as it is.
 */
return new class extends Migration
{
    /**
     * Retired default title, normalised => the Bangla title that replaces it.
     *
     * @var array<string, string>
     */
    private array $map = [
        'fastest shipping countrywide' => 'সারা দেশে দ্রুত ডেলিভারি',
        'easy return policy' => 'সহজ রিটার্ন পলিসি',
        'premium quality product' => 'প্রিমিয়াম কোয়ালিটি পণ্য',
        'online support 24/7' => '২৪/৭ অনলাইন সাপোর্ট',
    ];

    public function up(): void
    {
        $home = Setting::get('home_content');

        if (! is_array($home) || ! is_array($home['feature_strip'] ?? null)) {
            return;
        }

        $changed = false;
        $rows = [];

        foreach ($home['feature_strip'] as $row) {
            $title = is_array($row) ? $this->normalise($row['title'] ?? '') : null;

            if ($title !== null && isset($this->map[$title])) {
                $row['title'] = $this->map[$title];
                $changed = true;
            }

            $rows[] = $row;
        }

        if ($changed) {
            $home['feature_strip'] = $rows;
            Setting::put('home_content', $home);
        }
    }

    /** Case, edge whitespace and doubled spaces are typing noise, not a different item. */
    private function normalise(mixed $title): string
    {
        return preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $title)));
    }

    public function down(): void
    {
        // One-way on purpose: the English wording was retired, not lost.
    }
};
