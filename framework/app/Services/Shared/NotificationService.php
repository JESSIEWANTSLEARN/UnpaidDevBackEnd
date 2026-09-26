<?php

namespace App\Services\Shared;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Cross-domain notification and inventory/order alert service. */
class NotificationService
{
    private const LOW_STOCK_THRESHOLD = 10;
    private const EXPIRY_WARNING_DAYS = 30;

    private const INVENTORY_ROLES = [
        'super_admin',
        'Operations_Manager',
        'Purchasing_Manager',
        'Warehouse_Admin',
        'Purchasing_Staff',
        'Inventory_Controller',
    ];

    private const SALES_ROLES = [
        'super_admin',
        'Operations_Manager',
        'Sales_Manager',
        'Sales_Staff',
    ];

    public function recordNewOrder(
        int $orderId,
        int $customerUserId,
        string $customerName
    ): void {
        if (!$this->ready()) {
            return;
        }

        $this->notifyCustomerOrderStatus(
            $orderId,
            $customerUserId,
            'PENDING'
        );

        $stateKey = "order-internal:{$orderId}:new";

        if (!$this->hasState($stateKey)) {
            $this->notifyRoles(
                self::SALES_ROLES,
                'Yellow',
                "New customer order #{$orderId}",
                "{$customerName} placed order #{$orderId}. Review the new sales order."
            );

            $this->setState($stateKey, 'sent');
        }
    }

    public function syncCustomerOrderNotifications(int $userId): void
    {
        if (!$this->ready()) {
            return;
        }

        $orders = DB::table('WBO_Orders')
            ->where('customer_user_id', $userId)
            ->select('order_id', 'status')
            ->get();

        foreach ($orders as $order) {
            $this->notifyCustomerOrderStatus(
                (int) $order->order_id,
                $userId,
                (string) $order->status
            );
        }
    }

    public function syncOperationalAlerts(): void
    {
        if (!$this->ready()) {
            return;
        }

        $this->syncProductStockAlerts();
        $this->syncExpiryAlerts();
    }

    private function syncProductStockAlerts(): void
    {
        if (
            !Schema::hasTable('WBO_Products') ||
            !Schema::hasTable('WBO_Batches')
        ) {
            return;
        }

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
                DB::raw('SUM(current_quantity) AS available_stock')
            )
            ->groupBy('product_id');

        $products = DB::table('WBO_Products as p')
            ->leftJoinSub(
                $stockTotals,
                'stock',
                fn ($join) =>
                    $join->on(
                        'stock.product_id',
                        '=',
                        'p.product_id'
                    )
            )
            ->select(
                'p.product_id',
                'p.name',
                'p.is_visible',
                'p.reorder_point',
                DB::raw(
                    'COALESCE(stock.available_stock, 0) AS available_stock'
                )
            )
            ->get();

        $catalogBaselineExists =
            $this->hasState('system:catalog-notification-baseline');

        if (!$catalogBaselineExists) {
            foreach ($products as $product) {
                if ((bool) $product->is_visible) {
                    $this->setState(
                        "published:{$product->product_id}",
                        'seen'
                    );
                }

                $level = $this->stockLevel(
                    (int) $product->available_stock,
                    max(1, (int) $product->reorder_point)
                );

                $this->setState(
                    "stock-level:{$product->product_id}",
                    $level
                );

                if ($level !== 'normal') {
                    $this->sendInventoryStockAlert(
                        $product,
                        $level
                    );
                }
            }

            $this->setState(
                'system:catalog-notification-baseline',
                'ready'
            );

            return;
        }

        foreach ($products as $product) {
            $productId = (int) $product->product_id;
            $isVisible = (bool) $product->is_visible;
            $stock = (int) $product->available_stock;
            $level = $this->stockLevel(
                $stock,
                max(1, (int) $product->reorder_point)
            );

            if (
                $isVisible &&
                !$this->hasState("published:{$productId}")
            ) {
                $this->notifyAllCustomers(
                    'Yellow',
                    'New product available',
                    "{$product->name} is now available in the Walang Brownout store.",
                    $productId
                );

                $this->setState(
                    "published:{$productId}",
                    'seen'
                );
            }

            $stockKey = "stock-level:{$productId}";
            $previousLevel = $this->stateValue($stockKey);

            if ($previousLevel === null) {
                $this->setState($stockKey, $level);

                if ($level !== 'normal') {
                    $this->sendInventoryStockAlert(
                        $product,
                        $level
                    );
                }

                continue;
            }

            if ($previousLevel === $level) {
                continue;
            }

            $this->resolveInventoryWarnings($productId);

            if (
                $previousLevel === 'out' &&
                $level !== 'out' &&
                $isVisible
            ) {
                $this->notifyAllCustomers(
                    'Yellow',
                    'Product back in stock',
                    "{$product->name} is available again. Current stock: {$stock}.",
                    $productId
                );
            }

            if ($level !== 'normal') {
                $this->sendInventoryStockAlert(
                    $product,
                    $level
                );
            }

            $this->setState($stockKey, $level);
        }
    }

    private function syncExpiryAlerts(): void
    {
        if (
            !Schema::hasTable('WBO_Batches') ||
            !Schema::hasTable('WBO_Products')
        ) {
            return;
        }

        $cutoff = now()
            ->addDays(self::EXPIRY_WARNING_DAYS)
            ->endOfDay();

        $batches = DB::table('WBO_Batches as b')
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'b.product_id'
            )
            ->where('b.current_quantity', '>', 0)
            ->whereNotNull('b.expiry_date')
            ->where('b.expiry_date', '<=', $cutoff)
            ->select(
                'b.batch_id',
                'b.batch_number',
                'b.product_id',
                'b.current_quantity',
                'b.expiry_date',
                'p.name as product_name'
            )
            ->get();

        $activeBatchIds = [];

        foreach ($batches as $batch) {
            $batchId = (int) $batch->batch_id;
            $activeBatchIds[] = $batchId;

            $expiryDate = Carbon::parse(
                (string) $batch->expiry_date
            );

            $stateKey =
                "expiry:{$batchId}:" .
                $expiryDate->format('Y-m-d');

            if ($this->hasState($stateKey)) {
                continue;
            }

            $expired = $expiryDate->isPast();

            $title = $expired
                ? "Expired batch: {$batch->product_name}"
                : "Batch expiring soon: {$batch->product_name}";

            $message = $expired
                ? "Batch {$batch->batch_number} has expired and still has {$batch->current_quantity} unit(s)."
                : "Batch {$batch->batch_number} expires on {$expiryDate->format('M d, Y')} and has {$batch->current_quantity} unit(s) remaining.";

            $this->notifyRoles(
                self::INVENTORY_ROLES,
                'Expiry',
                $title,
                $message,
                (int) $batch->product_id,
                $batchId
            );

            $this->setState($stateKey, 'sent');
        }

        $query = DB::table('WBO_Notifications')
            ->where('alert_tier', 'Expiry')
            ->whereNotNull('related_batch_id')
            ->where('status', '<>', 'RESOLVED');

        if ($activeBatchIds !== []) {
            $query->whereNotIn(
                'related_batch_id',
                $activeBatchIds
            );
        }

        $query->update([
            'status' => 'RESOLVED',
            'resolved_at' => now(),
        ]);
    }

    private function notifyCustomerOrderStatus(
        int $orderId,
        int $userId,
        string $status
    ): void {
        $status = strtoupper($status);

        if (
            !in_array(
                $status,
                ['PENDING', 'CONFIRMED', 'FULFILLED', 'CANCELLED'],
                true
            )
        ) {
            return;
        }

        $stateKey =
            "order-customer:{$orderId}:{$status}";

        if ($this->hasState($stateKey)) {
            return;
        }

        [$tier, $title, $message] = match ($status) {
            'CONFIRMED' => [
                'Yellow',
                "Order #{$orderId} confirmed",
                "Your order #{$orderId} has been confirmed and is being prepared.",
            ],
            'FULFILLED' => [
                'Yellow',
                "Order #{$orderId} fulfilled",
                "Your order #{$orderId} has been fulfilled successfully.",
            ],
            'CANCELLED' => [
                'Red',
                "Order #{$orderId} cancelled",
                "Your order #{$orderId} was cancelled. Open My Orders for the latest status.",
            ],
            default => [
                'Yellow',
                "Order #{$orderId} placed",
                "We received order #{$orderId}. It is currently pending review.",
            ],
        };

        $this->insertNotification(
            $userId,
            $tier,
            $title,
            $message
        );

        $this->setState($stateKey, 'sent');
    }

    private function sendInventoryStockAlert(
        object $product,
        string $level
    ): void {
        $stock = (int) $product->available_stock;

        if ($level === 'out') {
            $this->notifyRoles(
                self::INVENTORY_ROLES,
                'Red',
                "Out of stock: {$product->name}",
                "{$product->name} has reached 0 available units.",
                (int) $product->product_id
            );

            return;
        }

        $this->notifyRoles(
            self::INVENTORY_ROLES,
            'Orange',
            "Low stock: {$product->name}",
            "{$product->name} has {$stock} unit(s) available. Reorder point is " .
                max(1, (int) $product->reorder_point) . '.',
            (int) $product->product_id
        );
    }

    private function resolveInventoryWarnings(
        int $productId
    ): void {
        DB::table('WBO_Notifications')
            ->where('related_product_id', $productId)
            ->whereIn('alert_tier', ['Orange', 'Red'])
            ->where('status', '<>', 'RESOLVED')
            ->update([
                'status' => 'RESOLVED',
                'resolved_at' => now(),
            ]);
    }

    private function notifyRoles(
        array $roles,
        string $tier,
        string $title,
        string $message,
        ?int $productId = null,
        ?int $batchId = null
    ): void {
        foreach ($this->recipientIdsForRoles($roles) as $userId) {
            $this->insertNotification(
                (int) $userId,
                $tier,
                $title,
                $message,
                $productId,
                $batchId
            );
        }
    }

    private function notifyAllCustomers(
        string $tier,
        string $title,
        string $message,
        ?int $productId = null
    ): void {
        $customerIds = DB::table('WBO_Users')
            ->where('role', 'System_User')
            ->where('account_status', 'active')
            ->pluck('user_id');

        foreach ($customerIds as $customerId) {
            $this->insertNotification(
                (int) $customerId,
                $tier,
                $title,
                $message,
                $productId
            );
        }
    }

    private function recipientIdsForRoles(
        array $roles
    ): Collection {
        return DB::table('WBO_Users')
            ->whereIn('role', $roles)
            ->where('account_status', 'active')
            ->pluck('user_id');
    }

    private function insertNotification(
        int $recipientUserId,
        string $tier,
        string $title,
        string $message,
        ?int $productId = null,
        ?int $batchId = null
    ): void {
        DB::table('WBO_Notifications')->insert([
            'alert_tier' => $tier,
            'title' => $title,
            'message' => $message,
            'related_product_id' => $productId,
            'related_batch_id' => $batchId,
            'recipient_user_id' => $recipientUserId,
            'triggered_at' => now(),
            'status' => 'UNREAD',
            'acknowledged_at' => null,
            'resolved_at' => null,
        ]);
    }

    private function stockLevel(
        int $stock,
        int $reorderPoint
    ): string {
        if ($stock <= 0) {
            return 'out';
        }

        if ($stock <= max(1, $reorderPoint)) {
            return 'low';
        }

        return 'normal';
    }

    private function ready(): bool
    {
        return
            Schema::hasTable('WBO_Notifications') &&
            Schema::hasTable('WBO_NotificationState') &&
            Schema::hasTable('WBO_Users');
    }

    private function hasState(string $key): bool
    {
        return DB::table('WBO_NotificationState')
            ->where('state_key', $key)
            ->exists();
    }

    private function stateValue(string $key): ?string
    {
        $value = DB::table('WBO_NotificationState')
            ->where('state_key', $key)
            ->value('state_value');

        return $value === null ? null : (string) $value;
    }

    private function setState(
        string $key,
        ?string $value
    ): void {
        DB::table('WBO_NotificationState')
            ->updateOrInsert(
                ['state_key' => $key],
                [
                    'state_value' => $value,
                    'updated_at' => now(),
                ]
            );
    }
}
