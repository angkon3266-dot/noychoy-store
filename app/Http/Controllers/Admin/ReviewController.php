<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\ImageOptimizer;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $reviews = Review::with('product')
            ->when(in_array($status, array_keys(Review::STATUSES)), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'statuses' => Review::STATUSES,
            'current' => $status,
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'counts' => [
                'pending' => Review::where('status', 'pending')->count(),
                'approved' => Review::where('status', 'approved')->count(),
                'hidden' => Review::where('status', 'hidden')->count(),
            ],
        ]);
    }

    /**
     * Write down the reviews the store was given somewhere else.
     *
     * Most feedback this store receives never reaches the review form — it
     * arrives in Messenger or WhatsApp, days after delivery, and the owner
     * catches up on a whole product's worth in one sitting. So this takes one
     * product and as many reviews as were typed into it, each with the date it
     * was actually given, and each lands on the product page in order.
     */
    public function store(Request $request, ImageOptimizer $optimizer)
    {
        // Rows the owner opened and left empty are not a validation failure —
        // an extra blank row at the bottom of a form is just how typing goes.
        $request->merge(['reviews' => array_filter(
            (array) $request->input('reviews', []),
            fn ($row) => filled($row['author_name'] ?? null) || filled($row['body'] ?? null),
        )]);

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
            'reviews' => ['required', 'array', 'min:1', 'max:25'],
            'reviews.*.author_name' => ['required', 'string', 'max:120'],
            'reviews.*.phone' => ['nullable', 'string', 'max:20'],
            'reviews.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviews.*.title' => ['nullable', 'string', 'max:150'],
            'reviews.*.body' => ['nullable', 'string', 'max:2000'],
            'reviews.*.reviewed_on' => ['nullable', 'date'],
            'reviews.*.is_verified_buyer' => ['nullable', 'boolean'],
            'reviews.*.photos' => ['nullable', 'array', 'max:4'],
            'reviews.*.photos.*' => ['image', 'max:5120'],
        ], [
            'reviews.required' => 'Fill in at least one review — a name and what the customer wrote.',
            'reviews.*.author_name.required' => 'Every review needs the customer’s name.',
        ]);

        $productId = (int) $data['product_id'];
        $saved = 0;
        $seconds = 0;

        foreach ($data['reviews'] as $key => $row) {
            $photos = [];
            foreach ($request->file("reviews.$key.photos", []) as $file) {
                $photos[] = $optimizer->storeWebp($file, 'reviews', 1200, 80);
            }

            $review = new Review([
                'product_id' => $productId,
                'author_name' => $row['author_name'],
                'phone' => $row['phone'] ?? null,
                'rating' => $row['rating'],
                'title' => $row['title'] ?? null,
                'body' => $row['body'] ?? null,
                'photos' => $photos ?: null,
                'is_verified_buyer' => filter_var($row['is_verified_buyer'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || $this->boughtIt($row['phone'] ?? null, $productId),
                'status' => $data['status'],
            ]);

            // The date is the row's own timestamp rather than a second field
            // the rest of the site would have to learn about — and reviews
            // sharing a date step back a second each, so a batch typed in one
            // sitting keeps the order it was typed in.
            $review->created_at = $this->reviewedAt($row['reviewed_on'] ?? null)->subSeconds($seconds++);
            $review->updated_at = $review->created_at;
            $review->save();

            if ($review->status === 'approved') {
                $this->awardPoints($review);
            }

            $saved++;
        }

        $product = Product::find($productId);

        return back()
            ->with('success', $saved.' review'.($saved === 1 ? '' : 's').' added for '.$product?->name.'.')
            ->with('last_product_id', $productId);
    }

    /** Edit a review in place — the date included. */
    public function update(Request $request, Review $review)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'author_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
            'reviewed_on' => ['nullable', 'date'],
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
            'is_verified_buyer' => ['nullable', 'boolean'],
        ]);

        $wasApproved = $review->status === 'approved';

        $review->fill([
            'product_id' => $data['product_id'],
            'author_name' => $data['author_name'],
            'phone' => $data['phone'] ?? null,
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'is_verified_buyer' => $request->boolean('is_verified_buyer'),
            'status' => $data['status'],
        ]);

        // Keep the time of day the review already carries; only the calendar
        // date is the owner's to set, and reviews sharing a date still sort.
        $review->created_at = $this->reviewedAt(
            $data['reviewed_on'] ?? null,
            store_time($review->created_at)->format('H:i:s'),
        );
        $review->save();

        if (! $wasApproved && $review->status === 'approved') {
            $this->awardPoints($review);
        }

        return back()->with('success', 'Review updated.');
    }

    public function updateStatus(Request $request, Review $review)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
        ]);

        $review->update($data);

        if ($data['status'] === 'approved') {
            $this->awardPoints($review);
        }

        return back()->with('success', 'Review '.$data['status'].'.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return back()->with('success', 'Review deleted.');
    }

    /**
     * A date the owner typed, read as a Dhaka date and stored as UTC.
     *
     * Without the timezone step an evening entry lands on the next day, which
     * is exactly the kind of wrong date this screen exists to fix.
     */
    private function reviewedAt(?string $date, ?string $time = null): Carbon
    {
        $tz = config('store.timezone', 'Asia/Dhaka');

        if (blank($date)) {
            return now();
        }

        return Carbon::parse($date.' '.($time ?: now($tz)->format('H:i:s')), $tz)->utc();
    }

    /** Verified = this phone has an order containing this product. */
    private function boughtIt(?string $phone, int $productId): bool
    {
        if (blank($phone)) {
            return false;
        }

        return Order::where('customer_phone', bd_phone($phone))
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId))
            ->exists();
    }

    /**
     * Loyalty points for an approved review (+ a bonus when it has a photo).
     * The award is keyed to the review, so calling this twice pays once.
     */
    private function awardPoints(Review $review): void
    {
        if (! $review->customer_id) {
            return;
        }

        $loyalty = app(LoyaltyService::class);
        if (! $loyalty->enabled() || ! ($customer = $review->customer)) {
            return;
        }

        $hasPhoto = filled($review->photos);
        $points = $loyalty->reviewPoints() + ($hasPhoto ? $loyalty->reviewPhotoBonus() : 0);
        $loyalty->award($customer, $points, 'earn_review', 'Approved review'.($hasPhoto ? ' (with photo)' : ''), $review);
    }
}
