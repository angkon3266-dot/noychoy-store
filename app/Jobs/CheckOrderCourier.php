<?php

namespace App\Jobs;

use App\Models\Order;
use App\Rules\BdPhone;
use App\Services\BdCourierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Look a brand-new order's phone number up on BDCourier without being asked.
 *
 * Owner, 2026-09-24: "When a new order comes, the courier history with
 * BDCourier should be checked automatically. Skip for repeat buyers — they
 * should show earlier history."
 *
 * The case this is for is the first-time buyer. Nothing in the shop's own data
 * says whether a number it has never sold to accepts COD parcels, and by the
 * time the data exists the shop has already paid a delivery fee and a return
 * fee on the parcel that taught it. Checking at the moment the order arrives
 * means the answer is on the order page before anyone opens it, rather than a
 * click the person packing the parcel has to remember to make.
 *
 * A returning customer is the opposite case, and is skipped on purpose: the
 * shop's own record of every parcel it has sent them is already on that same
 * page ("Delivery reliability"), usually beside a BDCourier result an earlier
 * order paid for. A credit spent there buys an answer that was on screen
 * anyway, so the page shows the earlier one instead — however old it is, see
 * BdCourierService::stored().
 *
 * Every lookup costs a plan credit, so this is off until the shop turns it on
 * (Admin → Integrations), and even then skipReason() refuses to spend one
 * unless the order really is from a number nobody knows anything about.
 *
 * Queued, not done during checkout: one lookup is a synchronous HTTP call that
 * BdCourierService allows a full minute, and the buyer is waiting on their
 * redirect to the confirmation page.
 */
class CheckOrderCourier implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Not a Bangladeshi mobile, so BDCourier has nothing to answer with. */
    public const SKIP_NO_PHONE = 'no-phone';

    /** Has ordered from this shop before: show what is already known. */
    public const SKIP_REPEAT = 'repeat';

    /** A current result is already stored — a batch run, or the customer page. */
    public const SKIP_HAVE_RESULT = 'have-result';

    /**
     * Never retried. A retry would pay a second credit for the same answer,
     * and a lookup that failed is no worse than the "Not checked" the order
     * page showed before this job existed — the button is still there.
     */
    public int $tries = 1;

    /** One lookup at BdCourierService::TIMEOUT_SECONDS (60), plus slack. */
    public int $timeout = 90;

    /** An order deleted while this sat in the queue has nothing to check. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Order $order)
    {
        // CreateManualOrder dispatches from inside its transaction, so wait for
        // the commit: a worker that picked this up first would look for an order
        // that is not there yet, find nothing, and quietly do nothing.
        //
        // Assigned rather than declared — Queueable already carries the property,
        // and redeclaring it here is a fatal composition error.
        $this->afterCommit = true;
    }

    /**
     * Queue the lookup for an order that has just been placed.
     *
     * The decision is made here as well as in handle() so that an order placed
     * with the feature switched off — or by a customer who has bought before —
     * leaves no job in the queue at all. handle() asks again because minutes
     * pass in between, and in that time someone may have pressed the button.
     */
    public static function queueFor(Order $order): void
    {
        $bdCourier = app(BdCourierService::class);

        if ($bdCourier->autoCheckNewOrders() && self::skipReason($order, $bdCourier) === null) {
            self::dispatch($order);
        }
    }

    /**
     * Why this order must not spend a credit, or null when it should be looked
     * up. One of the SKIP_* constants.
     *
     * The order page reads this too, so that a panel with nothing in it says
     * which of these happened instead of implying nobody has looked.
     */
    public static function skipReason(Order $order, BdCourierService $bdCourier): ?string
    {
        $phone = bd_phone((string) $order->customer_phone);

        if ($phone === '' || ! preg_match(BdPhone::PATTERN, $phone)) {
            return self::SKIP_NO_PHONE;
        }

        // Any earlier order on the same number, which is how the rest of the
        // admin decides "repeat" too (the orders list's 🔁 badge, and
        // CustomerInsight::forPhone()'s is_repeat on this very page). Matched on
        // the phone rather than the customer record because a guest checkout and
        // an order typed in by hand may never have been joined to one.
        if (Order::where('customer_phone', $phone)->whereKeyNot($order->getKey())->exists()) {
            return self::SKIP_REPEAT;
        }

        return $bdCourier->cached($phone) !== null ? self::SKIP_HAVE_RESULT : null;
    }

    public function handle(BdCourierService $bdCourier): void
    {
        if (! $bdCourier->autoCheckNewOrders()) {
            return;
        }

        if (self::skipReason($this->order, $bdCourier) !== null) {
            return;
        }

        // check() handles its own transport failures — it returns ok:false and
        // logs at error level, which production keeps. Nothing to report here:
        // a number that could not be looked up is simply still unchecked, and
        // the order page's button says so.
        $bdCourier->check(bd_phone((string) $this->order->customer_phone));
    }
}
