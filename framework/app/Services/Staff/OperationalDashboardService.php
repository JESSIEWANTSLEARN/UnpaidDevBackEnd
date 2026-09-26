<?php

namespace App\Services\Staff;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds read-only operational dashboard data for staff roles and Super Admin previews.
 * Keeping dashboard aggregation here prevents the HTTP controller from becoming a query monolith.
 */
class OperationalDashboardService
{
    private const LOW_STOCK_THRESHOLD = 10;

    private const OPEN_PO_STATUSES = [
        'DRAFT',
        'PENDING_APPROVAL',
        'APPROVED',
        'ORDERED',
        'PARTIALLY_RECEIVED',
    ];

    public function build(string $role, bool $isPreview): array
    {
        $stockTotals = DB::table('WBO_Batches')
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
            ->groupBy('product_id');

        $products = DB::table('WBO_Products as p')
            ->leftJoin(
                'WBO_Categories as c',
                'c.category_id',
                '=',
                'p.category_id'
            )
            ->leftJoin(
                'WBO_Suppliers as s',
                's.supplier_id',
                '=',
                'p.supplier_id'
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
                'p.supplier_id',
                'p.reorder_point',
                'c.name as category',
                's.name as supplier_name',
                DB::raw(
                    'COALESCE(stock.available_stock, 0) AS available_stock'
                )
            )
            ->orderBy('p.name')
            ->get()
            ->map(function ($product) {
                $product->available_stock =
                    (int) $product->available_stock;
                $product->reorder_point =
                    max(1, (int) $product->reorder_point);
                $product->recommended_reorder_quantity =
                    $product->available_stock <=
                        $product->reorder_point
                        ? max(
                            ($product->reorder_point * 2) -
                                $product->available_stock,
                            1
                        )
                        : 0;
                $product->supplier_id =
                    $product->supplier_id === null
                        ? null
                        : (int) $product->supplier_id;
                $product->category =
                    $product->category ?: 'Uncategorized';
                return $product;
            });

        $suppliers = DB::table('WBO_Suppliers as s')
            ->leftJoin(
                'WBO_Products as p',
                'p.supplier_id',
                '=',
                's.supplier_id'
            )
            ->select(
                's.supplier_id',
                's.name',
                's.contact_number',
                's.email',
                's.address',
                's.lead_time_days',
                's.supplier_status',
                DB::raw(
                    'COUNT(p.product_id) AS product_count'
                )
            )
            ->groupBy(
                's.supplier_id',
                's.name',
                's.contact_number',
                's.email',
                's.address',
                's.lead_time_days',
                's.supplier_status'
            )
            ->orderBy('s.name')
            ->get()
            ->map(function ($supplier) {
                $supplier->lead_time_days =
                    (int) $supplier->lead_time_days;
                $supplier->product_count =
                    (int) $supplier->product_count;
                return $supplier;
            });

        $batches = DB::table('WBO_Batches as b')
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'b.product_id'
            )
            ->select(
                'b.batch_id',
                'b.product_id',
                'p.sku',
                'p.name as product_name',
                'b.batch_number',
                'b.quantity_received',
                'b.current_quantity',
                'b.received_date',
                'b.expiry_date'
            )
            ->orderByDesc('b.received_date')
            ->limit(100)
            ->get()
            ->map(function ($batch) {
                $batch->quantity_received =
                    (int) $batch->quantity_received;
                $batch->current_quantity =
                    (int) $batch->current_quantity;
                return $batch;
            });

        $transactions = DB::table('WBO_Transactions as t')
            ->join(
                'WBO_Batches as b',
                'b.batch_id',
                '=',
                't.batch_id'
            )
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'b.product_id'
            )
            ->leftJoin(
                'WBO_Users as u',
                'u.user_id',
                '=',
                't.performed_by_user_id'
            )
            ->select(
                't.transaction_id',
                't.batch_id',
                'b.batch_number',
                'p.product_id',
                'p.sku',
                'p.name as product_name',
                't.transaction_type',
                't.quantity_change',
                't.timestamp',
                't.order_id',
                't.purchase_order_id',
                't.reference_note',
                'u.name as performed_by'
            )
            ->orderByDesc('t.timestamp')
            ->limit(100)
            ->get()
            ->map(function ($transaction) {
                $transaction->quantity_change =
                    (int) $transaction->quantity_change;
                return $transaction;
            });

        $purchaseOrders = DB::table('WBO_PurchaseOrders as po')
            ->join(
                'WBO_Suppliers as s',
                's.supplier_id',
                '=',
                'po.supplier_id'
            )
            ->leftJoin(
                'WBO_PurchaseOrderDetails as pod',
                'pod.po_id',
                '=',
                'po.po_id'
            )
            ->leftJoin(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'pod.product_id'
            )
            ->leftJoin(
                'WBO_Users as creator',
                'creator.user_id',
                '=',
                'po.created_by_user_id'
            )
            ->leftJoin(
                'WBO_Users as approver',
                'approver.user_id',
                '=',
                'po.approved_by_user_id'
            )
            ->select(
                'po.po_id',
                'po.po_number',
                'po.supplier_id',
                's.name as supplier_name',
                'pod.po_detail_id',
                DB::raw(
                    'COALESCE(pod.product_id, 0) AS product_id'
                ),
                'p.sku',
                'p.name as product_name',
                DB::raw(
                    'COALESCE(pod.quantity_ordered, 0) AS quantity_ordered'
                ),
                DB::raw(
                    'COALESCE(pod.quantity_received, 0) AS quantity_received'
                ),
                DB::raw(
                    'COALESCE(pod.unit_cost, 0) AS unit_cost'
                ),
                'po.status',
                'po.created_at',
                'po.approved_at',
                'po.ordered_at',
                'po.received_at',
                'po.cancelled_at',
                'po.created_by_user_id',
                'creator.name as created_by',
                'po.approved_by_user_id',
                'approver.name as approved_by'
            )
            ->orderByDesc('po.created_at')
            ->limit(100)
            ->get()
            ->map(function ($po) {
                $po->product_id =
                    (int) $po->product_id;
                $po->quantity_ordered =
                    (int) $po->quantity_ordered;
                $po->quantity_received =
                    (int) $po->quantity_received;
                $po->unit_cost =
                    (float) $po->unit_cost;
                $po->created_by_user_id =
                    (int) $po->created_by_user_id;
                $po->approved_by_user_id =
                    $po->approved_by_user_id === null
                        ? null
                        : (int) $po->approved_by_user_id;
                return $po;
            });

        $orderTotals = DB::table('WBO_OrderDetails')
            ->select(
                'order_id',
                DB::raw(
                    'SUM(quantity * unit_price) AS total_amount'
                ),
                DB::raw(
                    'SUM(quantity) AS total_quantity'
                )
            )
            ->groupBy('order_id');

        $orders = DB::table('WBO_Orders as o')
            ->leftJoinSub(
                $orderTotals,
                'totals',
                fn($join) =>
                    $join->on(
                        'totals.order_id',
                        '=',
                        'o.order_id'
                    )
            )
            ->select(
                'o.order_id',
                'o.order_date',
                'o.status',
                DB::raw(
                    'COALESCE(totals.total_amount, o.total_amount, 0) AS total_amount'
                ),
                DB::raw(
                    'COALESCE(totals.total_quantity, 0) AS total_quantity'
                )
            )
            ->orderByDesc('o.order_date')
            ->limit(50)
            ->get()
            ->map(function ($order) {
                $order->total_amount =
                    (float) $order->total_amount;
                $order->total_quantity =
                    (int) $order->total_quantity;
                return $order;
            });

        $lowStockProducts = $products
            ->filter(
                fn($product) =>
                    $product->available_stock > 0 &&
                    $product->available_stock <=
                        $product->reorder_point
            )
            ->values();

        $outOfStockProducts = $products
            ->filter(
                fn($product) =>
                    $product->available_stock <= 0
            )
            ->values();

        $reorderNeeds = $products
            ->filter(
                fn($product) =>
                    $product->available_stock <=
                        $product->reorder_point
            )
            ->sortBy('available_stock')
            ->values();

        $openPurchaseOrders = $purchaseOrders
            ->filter(
                fn($po) =>
                    in_array(
                        $po->status,
                        self::OPEN_PO_STATUSES,
                        true
                    )
            )
            ->unique('po_id')
            ->count();

        $pendingApprovalPos = $purchaseOrders
            ->where(
                'status',
                'PENDING_APPROVAL'
            )
            ->unique('po_id')
            ->count();

        $orderedPos = $purchaseOrders
            ->where('status', 'ORDERED')
            ->unique('po_id')
            ->count();

        $today = now()->startOfDay();

        $stockMovementsToday = $transactions
            ->filter(
                fn($transaction) =>
                    $transaction->timestamp !== null &&
                    Carbon::parse(
                        $transaction->timestamp
                    )->greaterThanOrEqualTo($today)
            )
            ->count();

        $receiptsToday = $transactions
            ->filter(
                fn($transaction) =>
                    $transaction->transaction_type ===
                        'RECEIVE' &&
                    $transaction->timestamp !== null &&
                    Carbon::parse(
                        $transaction->timestamp
                    )->greaterThanOrEqualTo($today)
            )
            ->count();

        $expiryCutoff =
            now()->addDays(30)->endOfDay();

        $expiringBatches = $batches
            ->filter(function ($batch) use ($expiryCutoff) {
                if (
                    !$batch->expiry_date ||
                    $batch->current_quantity <= 0
                ) {
                    return false;
                }

                $expiry =
                    Carbon::parse($batch->expiry_date);

                return
                    $expiry->greaterThanOrEqualTo(
                        now()->startOfDay()
                    ) &&
                    $expiry->lessThanOrEqualTo(
                        $expiryCutoff
                    );
            })
            ->values();

        $metrics = [
            'total_products' =>
                $products->count(),
            'total_stock' =>
                (int) $products->sum('available_stock'),
            'low_stock_items' =>
                $lowStockProducts->count(),
            'out_of_stock' =>
                $outOfStockProducts->count(),
            'open_purchase_orders' =>
                $openPurchaseOrders,
            'pending_approval_pos' =>
                $pendingApprovalPos,
            'ordered_pos' =>
                $orderedPos,
            'active_suppliers' =>
                $suppliers
                    ->where(
                        'supplier_status',
                        'ACTIVE'
                    )
                    ->count(),
            'pending_orders' =>
                $orders
                    ->where('status', 'PENDING')
                    ->count(),
            'stock_movements_today' =>
                $stockMovementsToday,
            'receipts_today' =>
                $receiptsToday,
            'expiring_batches' =>
                $expiringBatches->count(),
            'reorder_needs' =>
                $reorderNeeds->count(),
        ];

        $alerts = collect();

        if ($metrics['out_of_stock'] > 0) {
            $alerts->push([
                'tone' => 'danger',
                'title' => 'Out of Stock',
                'message' =>
                    "{$metrics['out_of_stock']} product(s) currently have no available stock.",
            ]);
        }

        if ($metrics['low_stock_items'] > 0) {
            $alerts->push([
                'tone' => 'warning',
                'title' => 'Low Stock',
                'message' =>
                    "{$metrics['low_stock_items']} product(s) are at or below their configured reorder point.",
            ]);
        }

        if ($metrics['pending_approval_pos'] > 0) {
            $alerts->push([
                'tone' => 'warning',
                'title' => 'Purchase Orders Awaiting Approval',
                'message' =>
                    "{$metrics['pending_approval_pos']} purchase order(s) require Purchasing Manager review.",
            ]);
        }

        if ($metrics['expiring_batches'] > 0) {
            $alerts->push([
                'tone' => 'warning',
                'title' => 'Expiry Watch',
                'message' =>
                    "{$metrics['expiring_batches']} stocked batch(es) expire within 30 days.",
            ]);
        }

        if ($metrics['open_purchase_orders'] > 0) {
            $alerts->push([
                'tone' => 'info',
                'title' => 'Open Purchase Orders',
                'message' =>
                    "{$metrics['open_purchase_orders']} purchase order(s) remain open.",
            ]);
        }

        if ($metrics['pending_orders'] > 0) {
            $alerts->push([
                'tone' => 'info',
                'title' => 'Pending Sales Orders',
                'message' =>
                    "{$metrics['pending_orders']} customer order(s) are pending.",
            ]);
        }

        return [
            'role' => $role,
            'preview' => $isPreview,
            'current_user_id' =>
                (int) session('user_id'),
            'low_stock_threshold' =>
                self::LOW_STOCK_THRESHOLD,
            'metrics' => $metrics,
            'alerts' => $alerts->values(),
            'products' => $products,
            'suppliers' => $suppliers,
            'reorder_needs' =>
                $reorderNeeds,
            'low_stock_products' =>
                $lowStockProducts,
            'out_of_stock_products' =>
                $outOfStockProducts,
            'batches' => $batches,
            'expiring_batches' =>
                $expiringBatches,
            'transactions' => $transactions,
            'purchase_orders' =>
                $purchaseOrders,
            'orders' => $orders,
        ];

    }
}
