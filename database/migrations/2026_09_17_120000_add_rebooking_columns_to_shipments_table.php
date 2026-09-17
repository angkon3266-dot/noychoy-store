<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking an order with the courier more than once.
 *
 * The owner asked on 2026-09-17 to be able to send an order to Steadfast again
 * after it has changed — a new COD after an amendment, a corrected address —
 * because an order that had been booked once could never be booked again.
 *
 *  - invoice        What the consignment was booked under. Steadfast refuses a
 *                   repeated invoice, so the second booking of order 10023 goes
 *                   as "10023-2"; the webhook needs to find it by that name.
 *  - request        The exact payload that was sent, so the order page can say
 *                   precisely what the courier still holds after an edit.
 *  - superseded_at  Set on the older consignment when a newer one replaces it.
 *  - superseded_by  Which shipment replaced it.
 *  - delivered_after_superseded_at
 *                   Set when a consignment that had ALREADY been replaced is
 *                   then reported delivered: the rider took the old parcel to
 *                   the door. From then on a cancellation of the newer, unused
 *                   consignment must not cancel an order the customer received.
 *
 * All nullable: every shipment booked before today simply has none of them,
 * and the code treats that as "the only booking, sent under the order number".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipments', 'invoice')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->string('invoice')->nullable()->after('tracking_code')->index();
            });
        }

        if (! Schema::hasColumn('shipments', 'request')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->json('request')->nullable()->after('response');
            });
        }

        if (! Schema::hasColumn('shipments', 'superseded_at')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable()->after('request');
            });
        }

        if (! Schema::hasColumn('shipments', 'superseded_by')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->unsignedBigInteger('superseded_by')->nullable()->after('superseded_at');
            });
        }

        if (! Schema::hasColumn('shipments', 'delivered_after_superseded_at')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->timestamp('delivered_after_superseded_at')->nullable()->after('superseded_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'delivered_after_superseded_at')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('delivered_after_superseded_at');
            });
        }

        if (Schema::hasColumn('shipments', 'superseded_by')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('superseded_by');
            });
        }

        if (Schema::hasColumn('shipments', 'superseded_at')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('superseded_at');
            });
        }

        if (Schema::hasColumn('shipments', 'request')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('request');
            });
        }

        if (Schema::hasColumn('shipments', 'invoice')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropIndex(['invoice']);
            });
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('invoice');
            });
        }
    }
};
