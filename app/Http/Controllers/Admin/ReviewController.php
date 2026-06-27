<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

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
            'counts' => [
                'pending' => Review::where('status', 'pending')->count(),
                'approved' => Review::where('status', 'approved')->count(),
                'hidden' => Review::where('status', 'hidden')->count(),
            ],
        ]);
    }

    public function updateStatus(Request $request, Review $review)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Review::STATUSES))],
        ]);

        $review->update($data);

        // Reward the customer with loyalty points the first time a review is approved
        // (+ a bonus when the review includes at least one photo).
        if ($data['status'] === 'approved' && $review->customer_id) {
            $loyalty = app(\App\Services\LoyaltyService::class);
            if ($loyalty->enabled() && ($customer = $review->customer)) {
                $hasPhoto = filled($review->photos);
                $points = $loyalty->reviewPoints() + ($hasPhoto ? $loyalty->reviewPhotoBonus() : 0);
                $loyalty->award($customer, $points, 'earn_review', 'Approved review'.($hasPhoto ? ' (with photo)' : ''), $review);
            }
        }

        return back()->with('success', 'Review '.$data['status'].'.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return back()->with('success', 'Review deleted.');
    }
}
