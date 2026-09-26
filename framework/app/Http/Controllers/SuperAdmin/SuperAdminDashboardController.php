<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\SuperAdmin\SuperAdminReportService;
use App\Services\Shared\NotificationService;

use App\Http\Controllers\Concerns\HandlesSuperAdminSupport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Super Admin dashboard, audit-log, and reporting endpoints. */
class SuperAdminDashboardController extends Controller
{
    use HandlesSuperAdminSupport;

    // =========================================================
    // DASHBOARD API
    // =========================================================

    public function index(
        NotificationService $notifications
    ): JsonResponse {
        $this->authorizeSuperAdmin();

        $notifications->syncOperationalAlerts();

        return response()->json(
            $this->dashboardData()
        );
    }

    // =========================================================
    // EXPORT REPORT
    // =========================================================

    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $period = strtolower((string) $request->query('period', 'today'));
        $role = (string) $request->query('role', 'all');
        $category = strtolower((string) $request->query('category', 'all'));
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));

        $allowedPeriods = ['today', '7days', '28days', 'all'];
        $allowedCategories = [
            'all',
            'authentication',
            'catalog',
            'inventory',
            'purchase_orders',
            'sales',
            'users',
            'settings',
            'backup_reports',
            'security',
            'system',
        ];

        if (!in_array($period, $allowedPeriods, true)) {
            $period = 'today';
        }

        if (!in_array($category, $allowedCategories, true)) {
            $category = 'all';
        }

        $query = DB::table('WBO_AuditLogs as a')
            ->leftJoin('WBO_Users as u', 'u.user_id', '=', 'a.user_id')
            ->select(
                'a.log_id',
                'a.user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.role as user_role',
                'a.action',
                'a.description',
                'a.ip_address',
                'a.user_agent',
                'a.created_at'
            );

        // Database timestamps remain UTC. Period boundaries are calculated
        // from Philippine local time and converted back to UTC for querying.
        if ($period !== 'all') {
            $start = Carbon::now('Asia/Manila')->startOfDay();

            if ($period === '7days') {
                $start->subDays(6);
            } elseif ($period === '28days') {
                $start->subDays(27);
            }

            $query->where(
                'a.created_at',
                '>=',
                $start->copy()->utc()->format('Y-m-d H:i:s')
            );
        }

        if ($role !== '' && $role !== 'all') {
            if ($role === 'system') {
                $query->whereNull('a.user_id');
            } else {
                $query->where('u.role', $role);
            }
        }

        if ($search !== '') {
            $like = '%' . $search . '%';

            $query->where(function ($subQuery) use ($like) {
                $subQuery
                    ->where('u.name', 'like', $like)
                    ->orWhere('u.email', 'like', $like)
                    ->orWhere('u.role', 'like', $like)
                    ->orWhere('a.action', 'like', $like)
                    ->orWhere('a.description', 'like', $like)
                    ->orWhere('a.ip_address', 'like', $like);
            });
        }

        $categoryPatterns = [
            'authentication' => ['LOGIN%', 'LOGOUT%', 'ACCOUNT_VERIFIED%', 'OTP%', 'AUTH%', 'SIGNUP%'],
            'catalog' => ['PRODUCT%', 'CATEGORY%', 'SUPPLIER%'],
            'inventory' => ['STOCK%', 'INVENTORY%', 'BATCH%', 'TRANSACTION%'],
            'purchase_orders' => ['PURCHASE_ORDER%', 'PO%'],
            'sales' => ['SALES%', 'SALE%', 'ORDER%'],
            'users' => ['USER%'],
            'settings' => ['COMPANY%', 'SETTING%', 'NOTIFICATION%'],
            'backup_reports' => ['BACKUP%', 'REPORT%'],
            'security' => ['PASSWORD%', 'SECURITY%', 'TRUSTED%', 'SESSION%'],
        ];

        if ($category !== 'all') {
            if ($category === 'system') {
                $knownPatterns = array_merge(...array_values($categoryPatterns));

                $query->where(function ($subQuery) use ($knownPatterns) {
                    foreach ($knownPatterns as $pattern) {
                        $subQuery->where('a.action', 'not like', $pattern);
                    }
                });
            } else {
                $patterns = $categoryPatterns[$category] ?? [];

                $query->where(function ($subQuery) use ($patterns) {
                    foreach ($patterns as $index => $pattern) {
                        if ($index === 0) {
                            $subQuery->where('a.action', 'like', $pattern);
                        } else {
                            $subQuery->orWhere('a.action', 'like', $pattern);
                        }
                    }
                });
            }
        }

        $total = (clone $query)->count('a.log_id');
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $logs = $query
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.log_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($log) {
                $log->created_at = $log->created_at
                    ? Carbon::parse((string) $log->created_at, 'UTC')
                        ->setTimezone('Asia/Manila')
                        ->toIso8601String()
                    : null;

                return $log;
            });

        return response()->json([
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'timezone' => 'Asia/Manila',
        ]);
    }

    public function exportReport(
        Request $request,
        SuperAdminReportService $reports
    ): StreamedResponse|JsonResponse
    {
        $this->authorizeSuperAdmin();

        $type = strtolower(trim((string) $request->query('type', 'complete')));

        if (!$reports->supports($type)) {
            return response()->json([
                'message' => 'Invalid report type.',
            ], 422);
        }

        $label = $reports->label($type);
        $spreadsheet = $reports->build($type);

        $this->audit(
            $request,
            'REPORT_EXPORTED',
            "Exported {$label} XLSX report."
        );

        $fileName = 'WalangBrownout-'
            . Str::slug($label)
            . '-'
            . Carbon::now('Asia/Manila')->format('Y-m-d-His')
            . '.xlsx';

        return response()->streamDownload(
            function () use ($reports, $spreadsheet) {
                $reports->write($spreadsheet);
            },
            $fileName,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]
        );
    }

    // =========================================================
    // DASHBOARD DATA
    // =========================================================

    private function dashboardData(): array
    {
        $lowStockThreshold = 10;

        // CURRENT USER
        $currentUser = DB::table('WBO_Users')
            ->select('user_id', 'name', 'email', 'contact_number', 'role', 'account_status', 'email_verified_at', 'last_seen_at', 'created_at')
            ->where('user_id', session('user_id'))
            ->first();

        if (!$currentUser) {
            session()->invalidate();
            abort(401, 'The signed-in user no longer exists.');
        }

        // STOCK TOTALS
        $stockTotals = DB::table('WBO_Batches')
            ->select('product_id', DB::raw('SUM(current_quantity) AS available_stock'))
            ->groupBy('product_id');

        // PRIMARY PRODUCT IMAGES
        $primaryImages = DB::table('WBO_ProductImages')
            ->select(
                'product_id',
                DB::raw('MAX(CASE WHEN is_primary = 1 THEN image_id END) AS image_id'),
                DB::raw('MAX(CASE WHEN is_primary = 1 THEN image_path END) AS image_path')
            )
            ->groupBy('product_id');

        // PRODUCTS
        $products = DB::table('WBO_Products as p')
            ->leftJoin('WBO_Categories as c', 'c.category_id', '=', 'p.category_id')
            ->leftJoinSub($stockTotals, 'stock', fn($join) => $join->on('stock.product_id', '=', 'p.product_id'))
            ->leftJoinSub($primaryImages, 'images', fn($join) => $join->on('images.product_id', '=', 'p.product_id'))
            ->select(
                'p.product_id', 'p.sku', 'p.name', 'p.description', 'c.name as category',
                'p.supplier_id', 'p.abc_class', 'p.is_seasonal', 'p.is_visible', 'p.is_featured',
                'p.unit_cost', 'p.unit_price', 'p.created_at', 'p.updated_at',
                DB::raw('COALESCE(stock.available_stock, 0) AS available_stock'),
                'images.image_id',
                'images.image_path'
            )
            ->orderBy('p.product_id')
            ->get()
            ->map(function ($product) {
                $product->available_stock = (int) $product->available_stock;
                $product->unit_cost = (float) $product->unit_cost;
                $product->unit_price = (float) $product->unit_price;
                $product->is_seasonal = (bool) $product->is_seasonal;
                $product->is_visible = (bool) $product->is_visible;
                $product->is_featured = (bool) $product->is_featured;
                $product->category = $product->category ?: 'Uncategorized';

                if ($product->image_id) {
                    $product->image_url = $product->image_path
                        ? Storage::url($product->image_path)
                        : '/api/store/product-images/' . $product->image_id;
                } else {
                    $product->image_url = null;
                }

                unset($product->image_id, $product->image_path);

                return $product;
            });

        // BATCHES / INVENTORY
        $batches = DB::table('WBO_Batches as b')
            ->join('WBO_Products as p', 'p.product_id', '=', 'b.product_id')
            ->select('b.batch_id', 'b.product_id', 'p.sku', 'p.name as product_name', 'b.batch_number', 'b.quantity_received', 'b.current_quantity', 'b.received_date', 'b.expiry_date')
            ->orderByDesc('b.received_date')
            ->get();

        // SUPPLIERS
        $suppliers = DB::table('WBO_Suppliers as s')
            ->leftJoin('WBO_Products as p', 'p.supplier_id', '=', 's.supplier_id')
            ->select('s.supplier_id', 's.name', 's.contact_number', 's.email', 's.address', 's.lead_time_days', 's.supplier_status', DB::raw('COUNT(p.product_id) AS product_count'))
            ->groupBy('s.supplier_id', 's.name', 's.contact_number', 's.email', 's.address', 's.lead_time_days', 's.supplier_status')
            ->orderBy('s.name')
            ->get();

        // SALES ORDER TOTALS
        $orderTotals = DB::table('WBO_OrderDetails')
            ->select('order_id', DB::raw('SUM(quantity * unit_price) AS total_amount'), DB::raw('SUM(quantity) AS total_quantity'))
            ->groupBy('order_id');

        // SALES ORDERS
        $orders = DB::table('WBO_Orders as o')
            ->leftJoinSub($orderTotals, 'totals', fn($join) => $join->on('totals.order_id', '=', 'o.order_id'))
            ->select('o.order_id', 'o.customer_user_id', 'o.customer_name', 'o.customer_contact', 'o.order_date', 'o.status', 'o.fulfilled_at', 'o.cancelled_at', DB::raw('COALESCE(totals.total_amount, o.total_amount, 0) AS total_amount'), DB::raw('COALESCE(totals.total_quantity, 0) AS total_quantity'))
            ->orderByDesc('o.order_date')
            ->get()
            ->map(function ($order) {
                $order->total_amount = (float) $order->total_amount;
                $order->total_quantity = (int) $order->total_quantity;
                return $order;
            });

        // INVENTORY TRANSACTIONS
        $transactions = DB::table('WBO_Transactions as t')
            ->join('WBO_Batches as b', 'b.batch_id', '=', 't.batch_id')
            ->join('WBO_Products as p', 'p.product_id', '=', 'b.product_id')
            ->leftJoin('WBO_Users as u', 'u.user_id', '=', 't.performed_by_user_id')
            ->select('t.transaction_id', 't.batch_id', 'b.batch_number', 'p.product_id', 'p.sku', 'p.name as product_name', 't.transaction_type', 't.quantity_change', 't.timestamp', 't.order_id', 't.purchase_order_id', 't.reference_note', 't.performed_by_user_id', 'u.name as performed_by')
            ->orderByDesc('t.timestamp')
            ->limit(100)
            ->get();

        // PURCHASE ORDERS
        $purchaseOrders = DB::table('WBO_PurchaseOrders as po')
            ->join('WBO_Suppliers as s', 's.supplier_id', '=', 'po.supplier_id')
            ->leftJoin('WBO_PurchaseOrderDetails as pod', 'pod.po_id', '=', 'po.po_id')
            ->leftJoin('WBO_Products as p', 'p.product_id', '=', 'pod.product_id')
            ->leftJoin('WBO_Users as creator', 'creator.user_id', '=', 'po.created_by_user_id')
            ->leftJoin('WBO_Users as approver', 'approver.user_id', '=', 'po.approved_by_user_id')
            ->select(
                'po.po_id', 'po.po_number', 'po.supplier_id', 's.name as supplier_name', 'pod.po_detail_id',
                DB::raw('COALESCE(pod.product_id, 0) AS product_id'), 'p.sku', 'p.name as product_name',
                DB::raw('COALESCE(pod.quantity_ordered, 0) AS quantity'), DB::raw('COALESCE(pod.quantity_ordered, 0) AS quantity_ordered'),
                DB::raw('COALESCE(pod.quantity_received, 0) AS quantity_received'), DB::raw('COALESCE(pod.unit_cost, 0) AS unit_cost'),
                'po.status', 'po.created_at', 'po.approved_at', 'po.ordered_at', 'po.received_at', 'po.cancelled_at',
                'po.created_by_user_id', 'creator.name as created_by', 'po.approved_by_user_id', 'approver.name as approved_by'
            )
            ->orderByDesc('po.created_at')
            ->get()
            ->map(function ($purchaseOrder) {
                $purchaseOrder->product_id = (int) $purchaseOrder->product_id;
                $purchaseOrder->quantity = (int) $purchaseOrder->quantity;
                $purchaseOrder->quantity_ordered = (int) $purchaseOrder->quantity_ordered;
                $purchaseOrder->quantity_received = (int) $purchaseOrder->quantity_received;
                $purchaseOrder->unit_cost = (float) $purchaseOrder->unit_cost;
                return $purchaseOrder;
            });

        // USER PRESENCE
        $onlineCutoff = now()->subMinutes(5);
        $users = DB::table('WBO_Users')
            ->select('user_id', 'name', 'email', 'contact_number', 'role', 'account_status', 'last_seen_at', 'email_verified_at', 'created_at')
            ->orderBy('user_id')
            ->get()
            ->map(function ($user) use ($onlineCutoff) {
                $user->is_online = $user->account_status === 'active' && $user->last_seen_at !== null && Carbon::parse($user->last_seen_at)->greaterThanOrEqualTo($onlineCutoff);
                return $user;
            });

        // NOTIFICATIONS
        $notifications = DB::table('WBO_Notifications as n')
            ->where('n.recipient_user_id', $currentUser->user_id)
            ->leftJoin('WBO_Products as p', 'p.product_id', '=', 'n.related_product_id')
            ->leftJoin('WBO_Batches as b', 'b.batch_id', '=', 'n.related_batch_id')
            ->leftJoin('WBO_Users as u', 'u.user_id', '=', 'n.recipient_user_id')
            ->select('n.notification_id', 'n.alert_tier', 'n.title', 'n.message', 'n.related_product_id', 'p.name as product_name', 'n.related_batch_id', 'b.batch_number', 'n.recipient_user_id', 'u.name as recipient_name', 'n.triggered_at', 'n.status', 'n.acknowledged_at', 'n.resolved_at')
            ->orderByDesc('n.triggered_at')
            ->limit(50)
            ->get();

        // AUDIT LOGS
        $auditLogs = DB::table('WBO_AuditLogs as a')
            ->leftJoin('WBO_Users as u', 'u.user_id', '=', 'a.user_id')
            ->select('a.log_id', 'a.user_id', 'u.name as user_name', 'a.action', 'a.description', 'a.ip_address', 'a.user_agent', 'a.created_at')
            ->orderByDesc('a.created_at')
            ->limit(100)
            ->get();

        // CATEGORIES
        $categories = DB::table('WBO_Categories as c')
            ->leftJoin('WBO_Products as p', 'p.category_id', '=', 'c.category_id')
            ->leftJoinSub($stockTotals, 'category_stock', fn($join) => $join->on('category_stock.product_id', '=', 'p.product_id'))
            ->select('c.category_id', 'c.name as category', 'c.description', 'c.is_active', DB::raw('COUNT(p.product_id) AS product_count'), DB::raw('COALESCE(SUM(category_stock.available_stock), 0) AS total_stock'))
            ->groupBy('c.category_id', 'c.name', 'c.description', 'c.is_active')
            ->orderBy('c.name')
            ->get()
            ->map(function ($category) {
                $category->product_count = (int) $category->product_count;
                $category->total_stock = (int) $category->total_stock;
                $category->is_active = (bool) $category->is_active;
                return $category;
            });

        // MONTHLY REPORT METRICS
        $monthStart = now()->startOfMonth();

        $monthlyRevenue = DB::table('WBO_Orders as o')
            ->join('WBO_OrderDetails as od', 'od.order_id', '=', 'o.order_id')
            ->where('o.status', 'FULFILLED')
            ->where('o.order_date', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(od.quantity * od.unit_price), 0) AS total')
            ->value('total');

        $monthlyOrders = DB::table('WBO_Orders')->where('order_date', '>=', $monthStart)->count();

        $productsSold = DB::table('WBO_Orders as o')
            ->join('WBO_OrderDetails as od', 'od.order_id', '=', 'o.order_id')
            ->where('o.status', 'FULFILLED')
            ->where('o.order_date', '>=', $monthStart)
            ->sum('od.quantity');

        // STOCK TREND
        $trendStart = now()->startOfWeek()->subWeeks(5);
        $trendTransactions = DB::table('WBO_Transactions')
            ->select('quantity_change', 'timestamp')
            ->where('timestamp', '>=', $trendStart)
            ->orderBy('timestamp')
            ->get();

        $stockTrend = collect(range(0, 5))->map(function ($weekOffset) use ($trendStart, $trendTransactions) {
            $weekStart = Carbon::parse($trendStart)->copy()->addWeeks($weekOffset);
            $weekEnd = $weekStart->copy()->endOfWeek();

            $netMovement = $trendTransactions->filter(function ($transaction) use ($weekStart, $weekEnd) {
                return Carbon::parse($transaction->timestamp)->betweenIncluded($weekStart, $weekEnd);
            })->sum('quantity_change');

            return [
                'label' => $weekStart->format('M d'),
                'net_movement' => (int) $netMovement
            ];
        })->values();

        // USER ACTIVITY ANALYTICS
        // WBO_UserSessions timestamps are stored in UTC.
        // The dashboard displays Philippine-local dates and hours.
        $manilaNow = Carbon::now('Asia/Manila');
        $todayStartManila = $manilaNow->copy()->startOfDay();
        $todayEndManila = $todayStartManila->copy()->addDay();

        $todayStartUtc = $todayStartManila
            ->copy()
            ->utc()
            ->format('Y-m-d H:i:s');

        $todayEndUtc = $todayEndManila
            ->copy()
            ->utc()
            ->format('Y-m-d H:i:s');

        $activityStartManila = $todayStartManila
            ->copy()
            ->subDays(29);

        $activityStartUtc = $activityStartManila
            ->copy()
            ->utc()
            ->format('Y-m-d H:i:s');

        $trackedSessions = DB::table('WBO_UserSessions')
            ->where(function ($query) use ($activityStartUtc) {
                $query
                    ->where('logged_in_at', '>=', $activityStartUtc)
                    ->orWhere('last_activity_at', '>=', $activityStartUtc);
            })
            ->select(
                'session_id',
                'user_id',
                'logged_in_at',
                'last_activity_at',
                'logged_out_at',
                'is_active'
            )
            ->get();

        $todaySessions = $trackedSessions->filter(
            fn ($session) =>
                $session->logged_in_at &&
                (string) $session->logged_in_at >= $todayStartUtc &&
                (string) $session->logged_in_at < $todayEndUtc
        );

        $todayActiveUserIds = collect();

        foreach ($trackedSessions as $session) {
            foreach (['logged_in_at', 'last_activity_at'] as $field) {
                $value = $session->{$field};

                if (!$value) {
                    continue;
                }

                $utcValue = Carbon::parse(
                    (string) $value,
                    'UTC'
                );

                if (
                    $utcValue->gte(
                        Carbon::parse($todayStartUtc, 'UTC')
                    ) &&
                    $utcValue->lt(
                        Carbon::parse($todayEndUtc, 'UTC')
                    )
                ) {
                    $todayActiveUserIds->push(
                        (int) $session->user_id
                    );
                }
            }
        }

        $dailyActiveSets = [];

        for ($offset = 0; $offset < 30; $offset++) {
            $day = $activityStartManila
                ->copy()
                ->addDays($offset);

            $key = $day->format('Y-m-d');

            $dailyActiveSets[$key] = [];
        }

        foreach ($trackedSessions as $session) {
            $userId = (int) $session->user_id;

            foreach (['logged_in_at', 'last_activity_at'] as $field) {
                $value = $session->{$field};

                if (!$value) {
                    continue;
                }

                $localDate = Carbon::parse(
                    (string) $value,
                    'UTC'
                )
                    ->setTimezone('Asia/Manila')
                    ->format('Y-m-d');

                if (array_key_exists($localDate, $dailyActiveSets)) {
                    $dailyActiveSets[$localDate][$userId] = true;
                }
            }
        }

        $dailyActivity30 = collect(
            $dailyActiveSets
        )->map(
            function ($usersForDay, $date) {
                $day = Carbon::createFromFormat(
                    'Y-m-d',
                    $date,
                    'Asia/Manila'
                );

                return [
                    'date' => $date,
                    'label' => $day->format('M d'),
                    'short_label' => $day->format('D'),
                    'active_users' => count($usersForDay),
                ];
            }
        )->values();

        $hourlyCounts = array_fill(0, 24, 0);

        foreach ($todaySessions as $session) {
            $loginHour = (int) Carbon::parse(
                (string) $session->logged_in_at,
                'UTC'
            )
                ->setTimezone('Asia/Manila')
                ->format('G');

            $hourlyCounts[$loginHour]++;

            if ($session->last_activity_at) {
                $lastActivity = Carbon::parse(
                    (string) $session->last_activity_at,
                    'UTC'
                )->setTimezone('Asia/Manila');

                $lastActivityDate =
                    $lastActivity->format('Y-m-d');

                if (
                    $lastActivityDate ===
                    $todayStartManila->format('Y-m-d')
                ) {
                    $lastHour = (int) $lastActivity->format('G');

                    if ($lastHour !== $loginHour) {
                        $hourlyCounts[$lastHour]++;
                    }
                }
            }
        }

        $hourlyActivity = collect(
            range(0, 23)
        )->map(
            function ($hour) use ($hourlyCounts) {
                return [
                    'hour' => $hour,
                    'label' => Carbon::createFromTime(
                        $hour,
                        0,
                        0,
                        'Asia/Manila'
                    )->format('g A'),
                    'count' => (int) $hourlyCounts[$hour],
                ];
            }
        )->values();

        $peakCount = max($hourlyCounts);
        $peakHour = $peakCount > 0
            ? (int) array_search(
                $peakCount,
                $hourlyCounts,
                true
            )
            : null;

        $currentlyOnline = $users
            ->filter(
                fn ($user) =>
                    (bool) $user->is_online
            )
            ->count();

        $userActivity = [
            'timezone' => 'Asia/Manila',
            'definition' =>
                'Authenticated activity based on session login and latest activity timestamps.',
            'metrics' => [
                'active_users_today' =>
                    $todayActiveUserIds
                        ->unique()
                        ->count(),
                'sessions_today' =>
                    $todaySessions->count(),
                'currently_online' =>
                    $currentlyOnline,
                'peak_activity_hour' =>
                    $peakHour === null
                        ? null
                        : Carbon::createFromTime(
                            $peakHour,
                            0,
                            0,
                            'Asia/Manila'
                        )->format('g A'),
                'peak_activity_count' =>
                    $peakCount,
                'tracked_sessions_30d' =>
                    $trackedSessions->count(),
                'distinct_users_30d' =>
                    $trackedSessions
                        ->pluck('user_id')
                        ->unique()
                        ->count(),
            ],
            'daily_7' =>
                $dailyActivity30
                    ->slice(-7)
                    ->values(),
            'daily_30' =>
                $dailyActivity30,
            'hourly_today' =>
                $hourlyActivity,
        ];

        // DASHBOARD METRICS
        $openPurchaseOrders = $purchaseOrders->filter(fn($po) => in_array($po->status, ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'ORDERED', 'PARTIALLY_RECEIVED'], true))->unique('po_id')->count();

        $metrics = [
            'total_products' => $products->count(),
            'total_stock' => (int) $products->sum('available_stock'),
            'low_stock_items' => $products->filter(fn($product) => $product->available_stock > 0 && $product->available_stock <= $lowStockThreshold)->count(),
            'out_of_stock' => $products->filter(fn($product) => $product->available_stock <= 0)->count(),
            'total_suppliers' => $suppliers->count(),
            'pending_orders' => $orders->where('status', 'PENDING')->count(),
            'total_users' => $users->count(),
            'monthly_revenue' => (float) $monthlyRevenue,
            'monthly_orders' => (int) $monthlyOrders,
            'products_sold' => (int) $productsSold,
            'unread_notifications' => $notifications->where('status', 'UNREAD')->count(),
            'open_purchase_orders' => $openPurchaseOrders
        ];

        // FINAL RESPONSE
        return [
            'current_user' => $currentUser,
            'low_stock_threshold' => $lowStockThreshold,
            'metrics' => $metrics,
            'products' => $products,
            'batches' => $batches,
            'categories' => $categories,
            'suppliers' => $suppliers,
            'orders' => $orders,
            'transactions' => $transactions,
            'purchase_orders' => $purchaseOrders,
            'users' => $users,
            'notifications' => $notifications,
            'audit_logs' => $auditLogs,
            'stock_trend' => $stockTrend,
            'user_activity' => $userActivity,
            'settings' => $this->systemSettings(),
            'backups' => $this->backupList()
        ];
    }
}