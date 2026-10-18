<?php

namespace App\Services\Inventory;

use App\Services\Shared\NotificationService;
use Illuminate\Support\Facades\DB;

/** Reads authoritative inventory changes after a committed, authorized mutation. */
class InventoryUpdateService
{
    public function __construct(private NotificationService $notifications) {}

    public function forBatches(array $batchIds, ?int $poId = null): ?array
    {
        try {
            return $this->build($batchIds, $poId);
        } catch (\Throwable $error) {
            // The write has already committed. A refresh failure must never invite a duplicate submission.
            report($error);
            return null; // Clients fall back to their existing dashboard refresh without replaying the write.
        }
    }

    private function build(array $batchIds, ?int $poId): array
    {
        $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
        $productIds = DB::table('WBO_Batches')->whereIn('batch_id', $batchIds)
            ->distinct()->pluck('product_id')->all();

        if ($productIds === []) {
            return ['product_stocks' => [], 'batches' => []];
        }

        // Stock and expiry alerts must only inspect the products changed by this save.
        $this->notifications->syncOperationalAlerts($productIds);

        $stocks = DB::table('WBO_Products as p')
            ->leftJoinSub($this->stockTotals($productIds), 'stock', fn ($join) => $join->on('stock.product_id', '=', 'p.product_id'))
            ->whereIn('p.product_id', $productIds)
            ->select('p.product_id', 'p.category_id', DB::raw('COALESCE(stock.available_stock, 0) AS available_stock'))
            ->get()->map(function ($row) {
                $row->available_stock = (int) $row->available_stock;
                return $row;
            });

        $batches = DB::table('WBO_Batches as b')
            ->join('WBO_Products as p', 'p.product_id', '=', 'b.product_id')
            ->whereIn('b.batch_id', $batchIds)
            ->select('b.batch_id', 'b.product_id', 'p.sku', 'p.name as product_name', 'b.batch_number',
                'b.quantity_received', 'b.current_quantity', 'b.received_date', 'b.expiry_date')
            ->orderByDesc('b.received_date')->orderByDesc('b.batch_id')->get();

        // Match the dashboard's existing latest-100 movement window, rather than returning its entire history.
        $transactions = DB::table('WBO_Transactions as t')
            ->join('WBO_Batches as b', 'b.batch_id', '=', 't.batch_id')
            ->join('WBO_Products as p', 'p.product_id', '=', 'b.product_id')
            ->leftJoin('WBO_Users as u', 'u.user_id', '=', 't.performed_by_user_id')
            ->select('t.transaction_id', 't.batch_id', 'b.batch_number', 'p.product_id', 'p.sku',
                'p.name as product_name', 't.transaction_type', 't.quantity_change', 't.timestamp',
                't.order_id', 't.purchase_order_id', 't.reference_note', 'u.name as performed_by')
            ->when(session('role') === 'super_admin', fn ($query) => $query->addSelect('t.performed_by_user_id'))
            ->orderByDesc('t.timestamp')->orderByDesc('t.transaction_id')->limit(100)->get();

        // Aggregate in SQL; do not fetch the full catalog to calculate three stock counters.
        $threshold = 'CASE WHEN p.reorder_point > 1 THEN p.reorder_point ELSE 1 END';
        $metrics = DB::table('WBO_Products as p')
            ->leftJoinSub($this->stockTotals(), 'stock', fn ($join) => $join->on('stock.product_id', '=', 'p.product_id'))
            ->selectRaw('COALESCE(SUM(stock.available_stock), 0) AS total_stock')
            ->selectRaw("COALESCE(SUM(CASE WHEN stock.available_stock > 0 AND stock.available_stock <= {$threshold} THEN 1 ELSE 0 END), 0) AS low_stock_items")
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(stock.available_stock, 0) <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock')
            ->first();

        $update = [
            'product_stocks' => $stocks->map(fn ($row) => ['product_id' => (int) $row->product_id, 'available_stock' => (int) $row->available_stock]),
            'batches' => $batches,
            'transactions' => $transactions,
            'metrics' => array_map('intval', (array) $metrics),
            'today' => now()->toDateString(),
            'expiry_cutoff' => now()->addDays(30)->toDateString(),
        ];

        if ($poId !== null) {
            $update['purchase_order_ids'] = [$poId];
            $update['purchase_orders'] = $this->purchaseOrderRows($poId);
        }

        // Respect the original role-data boundary; operational roles never receive admin-only data.
        if (session('role') === 'super_admin') {
            $categoryIds = $stocks->pluck('category_id')->filter()->unique()->values()->all();
            $categoryStock = $this->stockTotals()->whereIn('product_id',
                DB::table('WBO_Products')->select('product_id')->whereIn('category_id', $categoryIds));
            $update['categories'] = DB::table('WBO_Categories as c')
                ->leftJoin('WBO_Products as p', 'p.category_id', '=', 'c.category_id')
                ->leftJoinSub($categoryStock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'p.product_id'))
                ->whereIn('c.category_id', $categoryIds)->select('c.category_id')
                ->selectRaw('COALESCE(SUM(stock.available_stock), 0) AS total_stock')
                ->groupBy('c.category_id')->get()->map(function ($row) { $row->total_stock = (int) $row->total_stock; return $row; });
            $update['notifications'] = DB::table('WBO_Notifications as n')
                ->where('n.recipient_user_id', session('user_id'))
                ->leftJoin('WBO_Products as p', 'p.product_id', '=', 'n.related_product_id')
                ->leftJoin('WBO_Batches as b', 'b.batch_id', '=', 'n.related_batch_id')
                ->leftJoin('WBO_Users as u', 'u.user_id', '=', 'n.recipient_user_id')
                ->select('n.notification_id', 'n.alert_tier', 'n.title', 'n.message', 'n.related_product_id', 'p.name as product_name', 'n.related_batch_id', 'b.batch_number', 'n.recipient_user_id', 'u.name as recipient_name', 'n.triggered_at', 'n.status', 'n.acknowledged_at', 'n.resolved_at')
                ->orderByDesc('n.triggered_at')->orderByDesc('n.notification_id')->limit(50)->get();
            $update['metrics']['unread_notifications'] = $update['notifications']->where('status', 'UNREAD')->count();
            $update['audit_logs'] = DB::table('WBO_AuditLogs as a')
                ->leftJoin('WBO_Users as u', 'u.user_id', '=', 'a.user_id')
                ->select('a.log_id', 'a.user_id', 'u.name as user_name', 'a.action', 'a.description', 'a.ip_address', 'a.user_agent', 'a.created_at')
                ->orderByDesc('a.created_at')->orderByDesc('a.log_id')->limit(100)->get();
            $update['stock_trend'] = $this->stockTrend();
        }

        return $update;
    }

    private function stockTotals(?array $productIds = null)
    {
        return DB::table('WBO_Batches')
            ->when($productIds !== null, fn ($query) => $query->whereIn('product_id', $productIds ?? []))
            ->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->select('product_id')->selectRaw('SUM(current_quantity) AS available_stock')->groupBy('product_id');
    }

    /** Six aggregate values instead of hydrating every movement from the last six weeks. */
    public function stockTrend(): array
    {
        $start = now()->startOfWeek()->subWeeks(5);
        $query = DB::table('WBO_Transactions')->where('timestamp', '>=', $start);
        for ($week = 0; $week < 6; $week++) {
            $from = $start->copy()->addWeeks($week);
            $until = $from->copy()->addWeek();
            $query->selectRaw("COALESCE(SUM(CASE WHEN timestamp >= ? AND timestamp < ? THEN quantity_change ELSE 0 END), 0) AS week_{$week}", [$from, $until]);
        }
        $totals = $query->first();
        return collect(range(0, 5))->map(fn ($week) => [
            'label' => $start->copy()->addWeeks($week)->format('M d'),
            'net_movement' => (int) ($totals->{"week_{$week}"} ?? 0),
        ])->all();
    }

    private function purchaseOrderRows(int $poId)
    {
        return DB::table('WBO_PurchaseOrders as po')
            ->join('WBO_Suppliers as s', 's.supplier_id', '=', 'po.supplier_id')
            ->leftJoin('WBO_PurchaseOrderDetails as pod', 'pod.po_id', '=', 'po.po_id')
            ->leftJoin('WBO_Products as p', 'p.product_id', '=', 'pod.product_id')
            ->leftJoin('WBO_Users as creator', 'creator.user_id', '=', 'po.created_by_user_id')
            ->leftJoin('WBO_Users as approver', 'approver.user_id', '=', 'po.approved_by_user_id')
            ->where('po.po_id', $poId)
            ->select('po.po_id', 'po.po_number', 'po.supplier_id', 's.name as supplier_name', 'pod.po_detail_id',
                'pod.product_id', 'p.sku', 'p.name as product_name', 'pod.quantity_ordered',
                'pod.quantity_ordered as quantity', 'pod.quantity_received', 'pod.unit_cost', 'po.status',
                'po.created_at', 'po.approved_at', 'po.ordered_at', 'po.received_at', 'po.cancelled_at',
                'po.created_by_user_id', 'creator.name as created_by', 'po.approved_by_user_id', 'approver.name as approved_by')
            ->orderBy('pod.po_detail_id')->get()->map(function ($row) {
                foreach (['product_id', 'quantity', 'quantity_ordered', 'quantity_received', 'created_by_user_id'] as $field) $row->{$field} = (int) $row->{$field};
                $row->unit_cost = (float) $row->unit_cost;
                $row->approved_by_user_id = $row->approved_by_user_id === null ? null : (int) $row->approved_by_user_id;
                return $row;
            });
    }
}
