<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Builds the read-only Sales Manager / Sales Staff dashboard payload.
 * Order state transitions and stock reservation remain in the controller workflow.
 */
class SalesDashboardService
{
    private const LOW_STOCK_THRESHOLD = 10;

    public function build(string $role, bool $isPreview): array
    {
        $stockTotals =
            DB::table('WBO_Batches')
                ->where(function ($query) {
                    $query
                        ->whereNull('expiry_date')
                        ->orWhereDate(
                            'expiry_date',
                            '>=',
                            now()->toDateString()
                        );
                })
                ->select(
                    'product_id',
                    DB::raw(
                        'SUM(current_quantity) AS available_stock'
                    )
                )
                ->groupBy(
                    'product_id'
                );

        $products =
            DB::table('WBO_Products as p')
                ->leftJoin(
                    'WBO_Categories as c',
                    'c.category_id',
                    '=',
                    'p.category_id'
                )
                ->leftJoinSub(
                    $stockTotals,
                    'stock',
                    fn($join) =>
                        $join->on(
                            'stock.product_id',
                            '=',
                            'p.product_id'
                        )
                )
                ->select(
                    'p.product_id',
                    'p.sku',
                    'p.name',
                    'c.name as category',
                    'p.unit_price',
                    'p.reorder_point',
                    DB::raw(
                        'COALESCE(stock.available_stock, 0) AS available_stock'
                    )
                )
                ->orderBy(
                    'p.name'
                )
                ->get()
                ->map(function ($product) {
                    $product
                        ->available_stock =
                        (int)
                        $product
                            ->available_stock;
                    $product->unit_price =
                        (float)
                        $product->unit_price;
                    $product->reorder_point =
                        max(
                            1,
                            (int) $product->reorder_point
                        );
                    $product->category =
                        $product->category ?:
                        'Uncategorized';

                    return $product;
                });

        $details =
            DB::table(
                'WBO_OrderDetails as od'
            )
                ->join(
                    'WBO_Products as p',
                    'p.product_id',
                    '=',
                    'od.product_id'
                )
                ->select(
                    'od.order_detail_id',
                    'od.order_id',
                    'od.product_id',
                    'p.sku',
                    'p.name as product_name',
                    'od.quantity',
                    'od.unit_price'
                )
                ->orderBy(
                    'od.order_detail_id'
                )
                ->get()
                ->groupBy(
                    'order_id'
                );

        $orders =
            DB::table('WBO_Orders as o')
                ->leftJoin(
                    'WBO_Users as u',
                    'u.user_id',
                    '=',
                    'o.customer_user_id'
                )
                ->select(
                    'o.order_id',
                    'o.customer_user_id',
                    'o.customer_name',
                    'o.customer_contact',
                    'u.email as customer_email',
                    'o.order_date',
                    'o.status',
                    'o.total_amount',
                    'o.fulfilled_at',
                    'o.cancelled_at'
                )
                ->orderByDesc(
                    'o.order_date'
                )
                ->limit(150)
                ->get()
                ->map(
                    function ($order) use (
                        $details
                    ) {
                        $items =
                            collect(
                                $details->get(
                                    $order->order_id,
                                    []
                                )
                            )
                                ->map(
                                    function (
                                        $item
                                    ) {
                                        return [
                                            'order_detail_id' =>
                                                $item
                                                    ->order_detail_id,
                                            'product_id' =>
                                                $item
                                                    ->product_id,
                                            'sku' =>
                                                $item->sku,
                                            'product_name' =>
                                                $item
                                                    ->product_name,
                                            'quantity' =>
                                                (int)
                                                $item
                                                    ->quantity,
                                            'unit_price' =>
                                                (float)
                                                $item
                                                    ->unit_price,
                                            'line_total' =>
                                                (float)
                                                $item
                                                    ->unit_price *
                                                (int)
                                                $item
                                                    ->quantity,
                                        ];
                                    }
                                )
                                ->values();

                        $calculatedTotal =
                            (float)
                            $items->sum(
                                'line_total'
                            );

                        return [
                            'order_id' =>
                                (int)
                                $order->order_id,
                            'customer_user_id' =>
                                (int)
                                $order
                                    ->customer_user_id,
                            'customer_name' =>
                                $order
                                    ->customer_name,
                            'customer_contact' =>
                                $order
                                    ->customer_contact,
                            'customer_email' =>
                                $order
                                    ->customer_email,
                            'order_date' =>
                                $order
                                    ->order_date,
                            'status' =>
                                $order->status,
                            'total_amount' =>
                                $calculatedTotal > 0
                                    ? $calculatedTotal
                                    : (float)
                                      $order
                                          ->total_amount,
                            'total_quantity' =>
                                (int)
                                $items->sum(
                                    'quantity'
                                ),
                            'fulfilled_at' =>
                                $order
                                    ->fulfilled_at,
                            'cancelled_at' =>
                                $order
                                    ->cancelled_at,
                            'items' =>
                                $items,
                        ];
                    }
                );

        $customers =
            DB::table('WBO_Users as u')
                ->leftJoin(
                    'WBO_Orders as o',
                    function ($join) {
                        $join
                            ->on(
                                'o.customer_user_id',
                                '=',
                                'u.user_id'
                            );
                    }
                )
                ->leftJoin(
                    'WBO_OrderDetails as od',
                    'od.order_id',
                    '=',
                    'o.order_id'
                )
                ->where(
                    'u.role',
                    'System_User'
                )
                ->select(
                    'u.user_id',
                    'u.name',
                    'u.email',
                    'u.contact_number',
                    'u.account_status',
                    DB::raw(
                        'COUNT(DISTINCT o.order_id) AS order_count'
                    ),
                    DB::raw(
                        "COUNT(DISTINCT CASE WHEN o.status = 'FULFILLED' THEN o.order_id END) AS fulfilled_orders"
                    ),
                    DB::raw(
                        "COALESCE(SUM(CASE WHEN o.status = 'FULFILLED' THEN od.quantity * od.unit_price ELSE 0 END), 0) AS total_spent"
                    ),
                    DB::raw(
                        'MAX(o.order_date) AS last_order_at'
                    )
                )
                ->groupBy(
                    'u.user_id',
                    'u.name',
                    'u.email',
                    'u.contact_number',
                    'u.account_status'
                )
                ->orderByDesc(
                    'last_order_at'
                )
                ->get()
                ->map(function ($customer) {
                    $customer->order_count =
                        (int)
                        $customer->order_count;
                    $customer
                        ->fulfilled_orders =
                        (int)
                        $customer
                            ->fulfilled_orders;
                    $customer->total_spent =
                        (float)
                        $customer->total_spent;

                    return $customer;
                });

        $productPerformance =
            DB::table(
                'WBO_OrderDetails as od'
            )
                ->join(
                    'WBO_Orders as o',
                    'o.order_id',
                    '=',
                    'od.order_id'
                )
                ->join(
                    'WBO_Products as p',
                    'p.product_id',
                    '=',
                    'od.product_id'
                )
                ->where(
                    'o.status',
                    'FULFILLED'
                )
                ->select(
                    'p.product_id',
                    'p.sku',
                    'p.name',
                    DB::raw(
                        'SUM(od.quantity) AS units_sold'
                    ),
                    DB::raw(
                        'SUM(od.quantity * od.unit_price) AS revenue'
                    ),
                    DB::raw(
                        'COUNT(DISTINCT o.order_id) AS order_count'
                    )
                )
                ->groupBy(
                    'p.product_id',
                    'p.sku',
                    'p.name'
                )
                ->orderByDesc(
                    'units_sold'
                )
                ->get()
                ->map(function ($row) {
                    $row->units_sold =
                        (int)
                        $row->units_sold;
                    $row->revenue =
                        (float)
                        $row->revenue;
                    $row->order_count =
                        (int)
                        $row->order_count;

                    return $row;
                });

        $monthStart =
            now()->startOfMonth();

        $monthlyRevenue =
            DB::table(
                'WBO_Orders as o'
            )
                ->join(
                    'WBO_OrderDetails as od',
                    'od.order_id',
                    '=',
                    'o.order_id'
                )
                ->where(
                    'o.status',
                    'FULFILLED'
                )
                ->where(
                    'o.order_date',
                    '>=',
                    $monthStart
                )
                ->selectRaw(
                    'COALESCE(SUM(od.quantity * od.unit_price), 0) AS total'
                )
                ->value('total');

        $monthlyFulfilled =
            DB::table('WBO_Orders')
                ->where(
                    'status',
                    'FULFILLED'
                )
                ->where(
                    'order_date',
                    '>=',
                    $monthStart
                )
                ->count();

        $lowStock =
            $products
                ->filter(
                    fn($product) =>
                        $product
                            ->available_stock <=
                        $product->reorder_point
                )
                ->values();

        $metrics = [
            'total_orders' =>
                $orders->count(),
            'pending_orders' =>
                $orders
                    ->where(
                        'status',
                        'PENDING'
                    )
                    ->count(),
            'processing_orders' =>
                $orders
                    ->where(
                        'status',
                        'PROCESSING'
                    )
                    ->count(),
            'fulfilled_orders' =>
                $orders
                    ->where(
                        'status',
                        'FULFILLED'
                    )
                    ->count(),
            'unfulfilled_orders' =>
                $orders
                    ->where(
                        'status',
                        'UNFULFILLED'
                    )
                    ->count(),
            'cancelled_orders' =>
                $orders
                    ->where(
                        'status',
                        'CANCELLED'
                    )
                    ->count(),
            'monthly_revenue' =>
                (float)
                ($monthlyRevenue ?? 0),
            'monthly_fulfilled' =>
                $monthlyFulfilled,
            'customers' =>
                $customers->count(),
            'low_stock_products' =>
                $lowStock->count(),
        ];

        $alerts = collect();

        if (
            $metrics['pending_orders'] > 0
        ) {
            $alerts->push([
                'tone' => 'warning',
                'title' =>
                    'Orders Awaiting Review',
                'message' =>
                    "{$metrics['pending_orders']} order(s) are still pending Sales Staff review.",
            ]);
        }

        if (
            $metrics[
                'unfulfilled_orders'
            ] > 0
        ) {
            $alerts->push([
                'tone' => 'danger',
                'title' =>
                    'Unfulfilled Orders',
                'message' =>
                    "{$metrics['unfulfilled_orders']} order(s) are currently marked unfulfilled.",
            ]);
        }

        if (
            $metrics[
                'low_stock_products'
            ] > 0
        ) {
            $alerts->push([
                'tone' => 'warning',
                'title' =>
                    'Fulfillment Stock Risk',
                'message' =>
                    "{$metrics['low_stock_products']} product(s) are at or below their configured reorder point.",
            ]);
        }

        if (
            $metrics[
                'processing_orders'
            ] > 0
        ) {
            $alerts->push([
                'tone' => 'info',
                'title' =>
                    'Orders in Processing',
                'message' =>
                    "{$metrics['processing_orders']} order(s) currently have stock reserved for fulfillment.",
            ]);
        }

        return [
            'role' => $role,
            'preview' => $isPreview,
            'low_stock_threshold' =>
                self::LOW_STOCK_THRESHOLD,
            'metrics' => $metrics,
            'alerts' =>
                $alerts->values(),
            'orders' => $orders,
            'customers' => $customers,
            'products' => $products,
            'product_performance' =>
                $productPerformance,
            'low_stock_products' =>
                $lowStock,
        ];

    }
}
