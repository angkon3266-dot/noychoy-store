<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the CRM / segmentation / campaign-analytics queries added this
 * cycle. RFM, segments and win-back filter on customers.last_order_at /
 * total_spent / total_orders; campaign attribution filters orders by
 * customer_id + created_at. Without these they were full table scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->add('customers', ['last_order_at'], 'customers_last_order_at_index');
        $this->add('customers', ['total_spent'], 'customers_total_spent_index');
        $this->add('customers', ['total_orders'], 'customers_total_orders_index');
        $this->add('orders', ['customer_id', 'created_at'], 'orders_customer_created_index');
    }

    public function down(): void
    {
        $this->drop('customers', 'customers_last_order_at_index');
        $this->drop('customers', 'customers_total_spent_index');
        $this->drop('customers', 'customers_total_orders_index');
        $this->drop('orders', 'orders_customer_created_index');
    }

    /** Add an index, ignoring "already exists" so the migration is safe to re-run. */
    protected function add(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Throwable $e) {
            // Index already present — nothing to do.
        }
    }

    protected function drop(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        } catch (\Throwable $e) {
            // Not present — nothing to do.
        }
    }
};
