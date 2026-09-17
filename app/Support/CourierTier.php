<?php

namespace App\Support;

use App\Services\BdCourierService;

/**
 * Customer value tiers, read off how many parcels a phone number has sent
 * through every courier in Bangladesh (BDCourier's `total_parcel`).
 *
 * Owner, 2026-09-17: "From my customer data, using phone number find out the
 * high value customers using their courier data and categorise them so I can
 * call and follow them. 200–300, 300–600 and so on… categorise them by colour
 * code too." Asked which number to tier by, she chose TOTAL parcels rather
 * than delivered ones — a woman who orders four hundred parcels a year and
 * refuses a few is still somebody worth a phone call, and the delivered rate
 * sits beside the tier as its own badge so neither number hides the other.
 *
 * The brackets and their colours live here and nowhere else: the customer
 * list's slicer, its Courier column, the customer page and the CSV export all
 * read this class. A lower bound is inclusive and an upper bound is not, so
 * exactly 300 parcels is "300–600", never "200–300".
 *
 * The colour classes are strings in PHP, and Tailwind only scans the Blade
 * views and the JSX (resources/css/app.css, `@source`), so a class that
 * appears only here would never be generated — not even by a fresh build.
 * Every class below is one the committed admin CSS already carries, and
 * CustomerCourierTiersTest fails if that ever stops being true. Darker means
 * more parcels: two soft tints, two deeper ones, then solid violet and
 * black-and-gold for the customers the shop can least afford to lose.
 */
final class CourierTier
{
    /** The slicer key for customers whose number has never been looked up. */
    public const UNCHECKED = 'unchecked';

    /**
     * Ordered lightest to darkest. `max` is exclusive; null means no ceiling.
     *
     * @var array<string, array{label:string, min:int, max:int|null, badge:string}>
     */
    public const TIERS = [
        'under-100' => ['label' => 'Under 100', 'min' => 0, 'max' => 100, 'badge' => 'bg-ink-100 text-ink-700'],
        '100-200' => ['label' => '100–200', 'min' => 100, 'max' => 200, 'badge' => 'bg-blue-100 text-blue-700'],
        '200-300' => ['label' => '200–300', 'min' => 200, 'max' => 300, 'badge' => 'bg-emerald-200 text-emerald-900'],
        '300-600' => ['label' => '300–600', 'min' => 300, 'max' => 600, 'badge' => 'bg-amber-200 text-amber-900'],
        '600-1000' => ['label' => '600–1,000', 'min' => 600, 'max' => 1000, 'badge' => 'bg-violet-600 text-white'],
        '1000-plus' => ['label' => '1,000+', 'min' => 1000, 'max' => null, 'badge' => 'bg-ink-900 text-gold-300'],
    ];

    /**
     * The delivered-rate badge, keyed by the tone BdCourierService::risk()
     * hands back. Outlined rather than filled on purpose: the green, amber and
     * red here sit right next to an emerald or amber tier, and a filled badge
     * beside a filled badge read as one colour code instead of two.
     *
     * @var array<string, string>
     */
    public const DELIVERY_TONES = [
        'green' => 'border-green-300 text-green-700',
        'amber' => 'border-amber-300 text-amber-800',
        'red' => 'border-red-300 text-red-700',
        'ink' => 'border-ink-200 text-ink-700/60',
    ];

    /**
     * Every tier, lightest first, each carrying its own key.
     *
     * @return array<string, array{key:string, label:string, min:int, max:int|null, badge:string}>
     */
    public static function all(): array
    {
        $tiers = [];

        foreach (self::TIERS as $key => $tier) {
            $tiers[$key] = ['key' => $key] + $tier;
        }

        return $tiers;
    }

    /** One tier by its slicer key, or null for anything that is not a tier. */
    public static function find(?string $key): ?array
    {
        return $key !== null && isset(self::TIERS[$key]) ? ['key' => $key] + self::TIERS[$key] : null;
    }

    /**
     * The tier a parcel count falls in. Anything below zero — which the API
     * should never send — is treated as none at all.
     *
     * @return array{key:string, label:string, min:int, max:int|null, badge:string}
     */
    public static function forTotal(int $total): array
    {
        foreach (array_reverse(self::all(), true) as $tier) {
            if ($total >= $tier['min']) {
                return $tier;
            }
        }

        return self::find('under-100');
    }

    /**
     * The small "92% delivered" badge that sits beside a tier.
     *
     * The bands are BdCourierService::risk(), so the owner's Safe / Warning
     * thresholds under Integrations colour this exactly as they colour the
     * order page. The percentage is rounded DOWN: 79.6% shown as "80%" in the
     * amber of a Warning would contradict the 80% line it has not reached.
     *
     * @return array{label:string, classes:string, level:string, note:string}
     */
    public static function delivery(float|int|string|null $ratio, int $total): array
    {
        $risk = app(BdCourierService::class)->risk([
            'total_parcel' => $total,
            'success_ratio' => (float) $ratio,
        ]);

        return [
            'label' => $total > 0 ? (int) floor((float) $ratio).'% delivered' : 'No parcels yet',
            'classes' => self::DELIVERY_TONES[$risk['tone']] ?? self::DELIVERY_TONES['ink'],
            'level' => $risk['level'],
            'note' => $risk['note'],
        ];
    }
}
