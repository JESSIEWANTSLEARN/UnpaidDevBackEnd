<?php

namespace App\Http\Controllers\Orders\Concerns;

use App\Models\WBOUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

trait ReturnWorkflowHelpers
{
    private function restock(
        object $return,
        int $staffUserId
    ): void {
        $items = DB::table('WBO_ReturnItems')
            ->where(
                'return_id',
                $return->return_id
            )
            ->get();

        foreach ($items as $item) {
            $remaining = (int) $item->quantity;

            $sales = DB::table(
                'WBO_Transactions as t'
            )
                ->join(
                    'WBO_Batches as b',
                    'b.batch_id',
                    '=',
                    't.batch_id'
                )
                ->where(
                    't.order_id',
                    $return->order_id
                )
                ->where(
                    't.transaction_type',
                    'SALE'
                )
                ->where(
                    'b.product_id',
                    $item->product_id
                )
                ->select(
                    't.transaction_id',
                    't.batch_id',
                    't.quantity_change'
                )
                ->orderBy('t.transaction_id')
                ->get();

            foreach ($sales as $sale) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min(
                    $remaining,
                    abs(
                        (int) $sale->quantity_change
                    )
                );

                if ($take <= 0) {
                    continue;
                }

                $batch = DB::table('WBO_Batches')
                    ->where(
                        'batch_id',
                        $sale->batch_id
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$batch) {
                    throw ValidationException::withMessages([
                        'action' => [
                            'The original inventory batch no longer exists.',
                        ],
                    ]);
                }

                DB::table('WBO_Batches')
                    ->where(
                        'batch_id',
                        $sale->batch_id
                    )
                    ->update([
                        'current_quantity' =>
                            (int) $batch
                                ->current_quantity +
                            $take,
                    ]);

                DB::table(
                    'WBO_Transactions'
                )->insert([
                    'batch_id' =>
                        $sale->batch_id,
                    'transaction_type' =>
                        'ADJUSTMENT',
                    'quantity_change' =>
                        $take,
                    'order_id' =>
                        $return->order_id,
                    'purchase_order_id' => null,
                    'reference_note' =>
                        "Return #{$return->return_id} restocked after inspection",
                    'performed_by_user_id' =>
                        $staffUserId,
                    'timestamp' => now(),
                ]);

                $remaining -= $take;
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'action' => [
                        'Unable to match all returned units to the original sale batches.',
                    ],
                ]);
            }
        }
    }

    private function updateReturn(
        int $returnId,
        array $values
    ): void {
        DB::table('WBO_ReturnRequests')
            ->where('return_id', $returnId)
            ->update([
                ...$values,
                'updated_at' => now(),
            ]);
    }

    private function assertStatus(
        object $row,
        string $expected
    ): void {
        if ($row->status !== $expected) {
            throw ValidationException::withMessages([
                'action' => [
                    "Return must be {$expected} before this action.",
                ],
            ]);
        }
    }

    private function payload(int $returnId): array
    {
        $row = DB::table(
            'WBO_ReturnRequests as r'
        )
            ->join(
                'WBO_Orders as o',
                'o.order_id',
                '=',
                'r.order_id'
            )
            ->join(
                'WBO_Users as u',
                'u.user_id',
                '=',
                'r.customer_user_id'
            )
            ->leftJoin(
                'WBO_Users as h',
                'h.user_id',
                '=',
                'r.handled_by_user_id'
            )
            ->where('r.return_id', $returnId)
            ->select(
                'r.*',
                'o.total_amount as order_total',
                'u.name as customer_name',
                'u.email as customer_email',
                'h.name as handled_by_name'
            )
            ->first();

        abort_unless(
            $row,
            404,
            'Return request not found.'
        );

        return $this->mapPayload(
            $row,
            $this->items([$returnId])
        );
    }

    private function items(
        array $returnIds
    ): Collection {
        if (!$returnIds) {
            return collect();
        }

        return DB::table('WBO_ReturnItems as ri')
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'ri.product_id'
            )
            ->whereIn(
                'ri.return_id',
                $returnIds
            )
            ->select(
                'ri.*',
                'p.sku',
                'p.name as product_name'
            )
            ->get()
            ->groupBy('return_id');
    }

    private function mapPayload(
        object $row,
        Collection $items
    ): array {
        return [
            'return_id' =>
                (int) $row->return_id,
            'order_id' =>
                (int) $row->order_id,
            'customer_user_id' =>
                (int) $row->customer_user_id,
            'customer_name' =>
                $row->customer_name,
            'customer_email' =>
                $row->customer_email,
            'status' => $row->status,
            'reason' => $row->reason,
            'inspection_disposition' =>
                $row->inspection_disposition,
            'inspection_notes' =>
                $row->inspection_notes,
            'refund_amount' =>
                (float) $row->refund_amount,
            'handled_by_name' =>
                $row->handled_by_name,
            'requested_at' =>
                $row->requested_at,
            'approved_at' =>
                $row->approved_at,
            'received_at' =>
                $row->received_at,
            'inspected_at' =>
                $row->inspected_at,
            'refunded_at' =>
                $row->refunded_at,
            'items' =>
                collect(
                    $items->get(
                        $row->return_id,
                        []
                    )
                )
                    ->map(fn ($item) => [
                        'return_item_id' =>
                            (int)
                            $item
                                ->return_item_id,
                        'order_detail_id' =>
                            (int)
                            $item
                                ->order_detail_id,
                        'product_id' =>
                            (int)
                            $item
                                ->product_id,
                        'sku' =>
                            $item->sku,
                        'product_name' =>
                            $item
                                ->product_name,
                        'quantity' =>
                            (int)
                            $item->quantity,
                        'unit_price' =>
                            (float)
                            $item->unit_price,
                    ])
                    ->values(),
        ];
    }

    private function ready(): void
    {
        if (self::$tablesReady === true) {
            return;
        }

        if (
            !Schema::hasTable(
                'WBO_ReturnRequests'
            ) ||
            !Schema::hasTable(
                'WBO_ReturnItems'
            )
        ) {
            abort(
                503,
                'Return workflow database tables are not installed yet.'
            );
        }

        self::$tablesReady = true;
    }

    private function customer(
        Request $request
    ): WBOUser {
        if (
            $request->session()->get(
                'logged_in'
            ) !== true ||
            $request->session()->get(
                'role'
            ) !== 'System_User'
        ) {
            abort(
                403,
                'System User access required.'
            );
        }

        $user = WBOUser::find(
            (int) $request->session()->get(
                'user_id'
            )
        );

        abort_unless(
            $user &&
            $user->account_status === 'active',
            401,
            'Account unavailable.'
        );

        return $user;
    }

    private function staffRead(
        Request $request
    ): array {
        if (
            session('logged_in') !== true ||
            !session('user_id')
        ) {
            abort(
                401,
                'Authentication required.'
            );
        }

        $sessionRole =
            (string) session('role');

        if (
            in_array(
                $sessionRole,
                self::RETURN_ROLES,
                true
            )
        ) {
            $user = WBOUser::find(
                (int) session('user_id')
            );

            abort_unless(
                $user &&
                $user->account_status === 'active',
                401,
                'Account unavailable.'
            );

            return [
                'role' => $sessionRole,
                'preview' => false,
            ];
        }

        if (
            $sessionRole === 'super_admin' &&
            $request->boolean('preview')
        ) {
            $previewRole = (string)
                $request->query(
                    'preview_role',
                    'Sales_Manager'
                );

            abort_unless(
                in_array(
                    $previewRole,
                    self::RETURN_ROLES,
                    true
                ),
                403,
                'Unsupported return-workflow preview role.'
            );

            return [
                'role' => $previewRole,
                'preview' => true,
            ];
        }

        abort(
            403,
            'Return workflow role required.'
        );
    }

    private function staffAction(
        string $action
    ): WBOUser {
        $allowedRoles = match ($action) {
            'approve',
            'reject',
            'refund' =>
                self::SALES_ROLES,
            'receive' =>
                ['Warehouse_Admin'],
            'inspect' =>
                ['Inventory_Controller'],
            default => [],
        };

        if (
            session('logged_in') !== true ||
            !in_array(
                (string) session('role'),
                $allowedRoles,
                true
            )
        ) {
            abort(
                403,
                'Your role cannot perform this return action.'
            );
        }

        $user = WBOUser::find(
            (int) session('user_id')
        );

        abort_unless(
            $user &&
            $user->account_status === 'active',
            401,
            'Account unavailable.'
        );

        return $user;
    }

    private function notifySales(
        string $title,
        string $message
    ): void {
        $ids = DB::table('WBO_Users')
            ->whereIn(
                'role',
                self::SALES_ROLES
            )
            ->where(
                'account_status',
                'active'
            )
            ->pluck('user_id');

        foreach ($ids as $id) {
            $this->notifyUser(
                (int) $id,
                $title,
                $message
            );
        }
    }

    private function notifyRoles(
        array $roles,
        string $title,
        string $message
    ): void {
        $ids = DB::table('WBO_Users')
            ->whereIn('role', $roles)
            ->where(
                'account_status',
                'active'
            )
            ->pluck('user_id');

        foreach ($ids as $id) {
            $this->notifyUser(
                (int) $id,
                $title,
                $message
            );
        }
    }

    private function notifyUser(
        int $userId,
        string $title,
        string $message
    ): void {
        if (
            !Schema::hasTable(
                'WBO_Notifications'
            )
        ) {
            return;
        }

        DB::table('WBO_Notifications')
            ->insert([
                'alert_tier' => 'Yellow',
                'title' =>
                    mb_substr(
                        $title,
                        0,
                        150
                    ),
                'message' =>
                    mb_substr(
                        $message,
                        0,
                        500
                    ),
                'related_product_id' => null,
                'related_batch_id' => null,
                'recipient_user_id' => $userId,
                'triggered_at' => now(),
                'status' => 'UNREAD',
                'acknowledged_at' => null,
                'resolved_at' => null,
            ]);
    }

    private function audit(
        Request $request,
        int $userId,
        string $action,
        string $description
    ): void {
        if (
            !Schema::hasTable(
                'WBO_AuditLogs'
            )
        ) {
            return;
        }

        DB::table('WBO_AuditLogs')
            ->insert([
                'user_id' => $userId,
                'action' => $action,
                'description' =>
                    mb_substr(
                        $description,
                        0,
                        500
                    ),
                'ip_address' => $request->ip(),
                'user_agent' =>
                    mb_substr(
                        (string)
                        $request->userAgent(),
                        0,
                        500
                    ),
                'created_at' => now(),
            ]);
    }
}