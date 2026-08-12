<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give existing courier-booked orders the new "booked" status.
 *
 * Until now, creating a Steadfast consignment wrote `shipped` straight away —
 * there was no status for "registered with the courier but still on the shelf".
 * The label sheet is now scoped to `booked`, so without this backfill every
 * order booked before today would silently drop off it and could never be
 * printed again.
 *
 * Only orders that actually have a consignment and have not been settled
 * (delivered / cancelled / returned are left alone) are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->whereIn('status', ['processing', 'shipped'])
            ->whereIn('id', fn ($q) => $q->select('order_id')->from('shipments')->whereNotNull('consignment_id'))
            ->update(['status' => 'booked']);
    }

    public function down(): void
    {
        // `shipped` is what these rows held before `booked` existed.
        DB::table('orders')->where('status', 'booked')->update(['status' => 'shipped']);
    }
};
