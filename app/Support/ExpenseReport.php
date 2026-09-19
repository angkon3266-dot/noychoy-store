<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * What the shop made after what it spent, for a window — the Expenses page's
 * summary and the dashboard's "Expenses & net profit" card.
 *
 *     Sales          what the orders came to: after discounts, with the
 *                    delivery charge the customer paid, cancelled and
 *                    returned orders left out (the dashboard's Sales figure)
 *   − Product cost   cost price + transport of the pieces in those orders
 *   = Gross profit
 *   − Expenses       logged in Admin → Expenses, except stock purchases
 *   = Net profit
 *
 * Built from order totals rather than from the "Revenue & profit" card, which
 * is item prices before discounts and without delivery: once the owner logs
 * the courier's bills here, the delivery charges customers paid have to be on
 * the other side, or every parcel would count as a pure loss.
 *
 * Stock purchases are money out but not a second cost — see
 * Expense::NOT_DEDUCTED — so they are reported beside the result, not in it.
 * Orders count by the day they were placed, as everywhere on the dashboard.
 */
final class ExpenseReport
{
    /**
     * @return array{sales: float, orders: int, product_cost: float, gross: float, expenses: float,
     *               stock_bought: float, spent: float, net: float, count: int,
     *               by_category: list<array{category: string, amount: float, count: int, deducted: bool, pct: float}>}
     */
    public static function for(DateRange $range): array
    {
        $sold = $range->constrain(Order::query()->whereNotIn('status', Order::NOT_SALES));
        $sales = (float) (clone $sold)->sum('total');
        $orders = (int) (clone $sold)->count();

        $productCost = (float) $range->constrain(
            OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNull('orders.deleted_at')
                ->whereNotIn('orders.status', Order::NOT_SALES),
            'orders.created_at',
        )
            ->selectRaw('COALESCE(SUM((COALESCE(order_items.cost_price, 0) + COALESCE(order_items.transport_cost, 0)) * order_items.quantity), 0) as cost')
            ->value('cost');

        $byCategory = Expense::query()->within($range)
            ->selectRaw('category, SUM(amount) as amount, COUNT(*) as n')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get();

        $spent = round((float) $byCategory->sum('amount'), 2);
        $stock = round((float) $byCategory->whereIn('category', Expense::NOT_DEDUCTED)->sum('amount'), 2);
        $expenses = round($spent - $stock, 2);

        return [
            'sales' => $sales,
            'orders' => $orders,
            'product_cost' => $productCost,
            'gross' => $sales - $productCost,
            'expenses' => $expenses,
            'stock_bought' => $stock,
            'spent' => $spent,
            'net' => $sales - $productCost - $expenses,
            'count' => (int) $byCategory->sum('n'),
            'by_category' => $byCategory->map(fn ($row) => [
                'category' => (string) $row->category,
                'amount' => (float) $row->amount,
                'count' => (int) $row->n,
                'deducted' => ! in_array($row->category, Expense::NOT_DEDUCTED, true),
                'pct' => $spent > 0 ? round((float) $row->amount / $spent * 100, 1) : 0.0,
            ])->values()->all(),
        ];
    }
}
