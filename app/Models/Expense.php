<?php

namespace App\Models;

use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money the business spent (owner, 2026-09-19). See Admin → Expenses.
 */
class Expense extends Model
{
    /** Suggested categories. The owner can type her own; these are the common ones. */
    public const CATEGORIES = [
        'Facebook / Instagram ads',
        'Courier & delivery',
        'Packaging',
        'Stock purchase',
        'Photography & content',
        'Salaries & staff',
        'Rent & utilities',
        'Internet & phone',
        'Website & software',
        'Transport',
        'bKash / bank charges',
        'Refunds & returns',
        'Other',
    ];

    /**
     * Money out that is not taken off profit a second time. Every sale already
     * deducts the cost price of the pieces sold (order_items.cost_price), so
     * stock bought becomes cost only as it sells — subtracting the purchase as
     * well would count those pieces twice.
     */
    public const NOT_DEDUCTED = ['Stock purchase'];

    public const PAID_VIA = ['Cash', 'bKash', 'Nagad', 'Rocket', 'Bank', 'Card'];

    protected $fillable = [
        'spent_on', 'category', 'amount', 'description', 'paid_to', 'paid_via', 'receipt_path', 'user_id',
    ];

    protected $casts = [
        'spent_on' => 'date',
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A typed category in its suggested spelling when it is one of the
     * suggestions ("stock purchase" is "Stock purchase"), so the list, the
     * totals and NOT_DEDUCTED all see one category, not two.
     */
    public static function normaliseCategory(string $category): string
    {
        $category = trim(preg_replace('/\s+/', ' ', $category));

        foreach (self::CATEGORIES as $known) {
            if (mb_strtolower($known) === mb_strtolower($category)) {
                return $known;
            }
        }

        return $category;
    }

    /**
     * Expenses dated inside a window. `spent_on` is a plain date, so the
     * window's bounds are compared as dates, not instants — through
     * whereDate, because Eloquent writes a date cast as "Y-m-d 00:00:00",
     * which a string comparison against "Y-m-d" puts after the last day.
     */
    public function scopeWithin(Builder $query, DateRange $range): Builder
    {
        if ($range->isAllTime()) {
            return $query;
        }

        return $query->whereDate('spent_on', '>=', $range->start->toDateString())
            ->whereDate('spent_on', '<=', $range->end->toDateString());
    }

    /** The ones that come off profit — everything but NOT_DEDUCTED. */
    public function scopeDeductible(Builder $query): Builder
    {
        return $query->whereNotIn('category', self::NOT_DEDUCTED);
    }

    public function isDeductible(): bool
    {
        return ! in_array($this->category, self::NOT_DEDUCTED, true);
    }
}
