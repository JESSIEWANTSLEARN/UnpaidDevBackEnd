<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Shared\NotificationService;
use App\Services\Sales\SalesDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
 * WBO_SALES_ROLE_CONTROLLER_V1
 *
 * Sales workflow:
 * PENDING -> PROCESSING -> FULFILLED
 *      \         \
 *       -> UNFULFILLED / CANCELLED
 *
 * PROCESSING reserves stock using FEFO. A reservation reduces available
 * batch stock and records RESERVE transactions. On fulfillment, those
 * reservations become SALE transactions without deducting stock twice.
 * If a processing order is cancelled/unfulfilled, reserved quantities are
 * returned through positive ADJUSTMENT transactions for a complete history.
 */
/** Sales Manager and Sales Staff dashboard/order workflow endpoints. */
class SalesRoleController extends Controller
{
    private const SALES_ROLES = [
        'Sales_Manager',
        'Sales_Staff',
    ];


    public function dashboard(
        Request $request,
        string $role,
        SalesDashboardService $dashboard
    ): JsonResponse {
        $isPreview =
            $this->authorizeRead($request, $role);

        return response()->json(
            $dashboard->build(
                $role,
                $isPreview
            )
        );
    }

    public function updateOrderStatus(
        Request $request,
        int $orderId,
        NotificationService $notifications
    ): JsonResponse {
        $role = $this->authorizeAction();

        $validated = $request->validate([
            'action' => [
                'required',
                Rule::in([
                    'process',
                    'fulfill',
                    'unfulfill',
                    'cancel',
                ]),
            ],
        ]);

        $action = $validated['action'];

        $result = DB::transaction(
            function () use (
                $orderId,
                $action,
                $role
            ) {
                $order =
                    DB::table('WBO_Orders')
                        ->where(
                            'order_id',
                            $orderId
                        )
                        ->lockForUpdate()
                        ->first();

                if (!$order) {
                    abort(404, 'Order not found.');
                }

                if ($action === 'process') {
                    if ($order->status !== 'PENDING') {
                        throw ValidationException::withMessages([
                            'action' => [
                                'Only pending orders can be moved to processing.',
                            ],
                        ]);
                    }

                    $reserved =
                        $this->reserveOrderStock(
                            $orderId
                        );

                    DB::table('WBO_Orders')
                        ->where(
                            'order_id',
                            $orderId
                        )
                        ->update([
                            'status' =>
                                'PROCESSING',
                            'fulfilled_at' =>
                                null,
                            'cancelled_at' =>
                                null,
                        ]);

                    return [
                        'status' =>
                            'PROCESSING',
                        'message' =>
                            "Order #{$orderId} is now processing. {$reserved} unit(s) reserved.",
                        'audit_action' =>
                            'ORDER_PROCESSING',
                    ];
                }

                if ($action === 'fulfill') {
                    if (
                        $order->status !==
                        'PROCESSING'
                    ) {
                        throw ValidationException::withMessages([
                            'action' => [
                                'Only processing orders can be fulfilled.',
                            ],
                        ]);
                    }

                    $salesCount =
                        DB::table(
                            'WBO_Transactions'
                        )
                            ->where(
                                'order_id',
                                $orderId
                            )
                            ->where(
                                'transaction_type',
                                'RESERVE'
                            )
                            ->update([
                                'transaction_type' =>
                                    'SALE',
                                'reference_note' =>
                                    "Fulfilled sale for order #{$orderId}",
                                'timestamp' =>
                                    now(),
                            ]);

                    if ($salesCount <= 0) {
                        throw ValidationException::withMessages([
                            'action' => [
                                'This processing order has no active stock reservation.',
                            ],
                        ]);
                    }

                    DB::table('WBO_Orders')
                        ->where(
                            'order_id',
                            $orderId
                        )
                        ->update([
                            'status' =>
                                'FULFILLED',
                            'fulfilled_at' =>
                                now(),
                            'cancelled_at' =>
                                null,
                        ]);

                    return [
                        'status' =>
                            'FULFILLED',
                        'message' =>
                            "Order #{$orderId} fulfilled successfully.",
                        'audit_action' =>
                            'ORDER_FULFILLED',
                    ];
                }

                if ($action === 'unfulfill') {
                    if (
                        !in_array(
                            $order->status,
                            [
                                'PENDING',
                                'PROCESSING',
                            ],
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'action' => [
                                'Only pending or processing orders can be marked unfulfilled.',
                            ],
                        ]);
                    }

                    if (
                        $order->status ===
                        'PROCESSING'
                    ) {
                        $this->releaseReservations(
                            $orderId,
                            "Order #{$orderId} marked unfulfilled"
                        );
                    }

                    DB::table('WBO_Orders')
                        ->where(
                            'order_id',
                            $orderId
                        )
                        ->update([
                            'status' =>
                                'UNFULFILLED',
                            'fulfilled_at' =>
                                null,
                            'cancelled_at' =>
                                null,
                        ]);

                    return [
                        'status' =>
                            'UNFULFILLED',
                        'message' =>
                            "Order #{$orderId} marked unfulfilled.",
                        'audit_action' =>
                            'ORDER_UNFULFILLED',
                    ];
                }

                if (
                    !in_array(
                        $order->status,
                        [
                            'PENDING',
                            'PROCESSING',
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'action' => [
                            'Only pending or processing orders can be cancelled.',
                        ],
                    ]);
                }

                if (
                    $order->status ===
                    'PROCESSING'
                ) {
                    $this->releaseReservations(
                        $orderId,
                        "Order #{$orderId} cancelled"
                    );
                }

                DB::table('WBO_Orders')
                    ->where(
                        'order_id',
                        $orderId
                    )
                    ->update([
                        'status' =>
                            'CANCELLED',
                        'fulfilled_at' =>
                            null,
                        'cancelled_at' =>
                            now(),
                    ]);

                return [
                    'status' =>
                        'CANCELLED',
                    'message' =>
                        "Order #{$orderId} cancelled.",
                    'audit_action' =>
                        'ORDER_CANCELLED',
                ];
            }
        );

        $this->audit(
            $request,
            $result['audit_action'],
            sprintf(
                '%s changed order #%d to %s.',
                $this->roleLabel($role),
                $orderId,
                $result['status']
            )
        );

        // Stock reservation/release may change operational alert state.
        $notifications->syncOperationalAlerts();

        return response()->json([
            'message' => $result['message'],
            'status' => $result['status'],
        ]);
    }

    private function reserveOrderStock(
        int $orderId
    ): int {
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
                ->where(
                    'od.order_id',
                    $orderId
                )
                ->select(
                    'od.product_id',
                    'od.quantity',
                    'p.name as product_name'
                )
                ->get();

        if ($details->isEmpty()) {
            throw ValidationException::withMessages([
                'action' => [
                    'This order does not contain any products.',
                ],
            ]);
        }

        $totalReserved = 0;

        foreach ($details as $detail) {
            $remaining =
                (int) $detail->quantity;

            /*
             * FEFO: expiring batches first. Batches without expiry are
             * used after dated batches, with older received stock first.
             */
            $batches =
                DB::table('WBO_Batches')
                    ->where(
                        'product_id',
                        $detail->product_id
                    )
                    ->where(
                        'current_quantity',
                        '>',
                        0
                    )
                    ->where(function ($query) {
                        $query
                            ->whereNull('expiry_date')
                            ->orWhereDate(
                                'expiry_date',
                                '>=',
                                now()->toDateString()
                            );
                    })
                    ->orderByRaw(
                        'CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END'
                    )
                    ->orderBy(
                        'expiry_date'
                    )
                    ->orderBy(
                        'received_date'
                    )
                    ->orderBy(
                        'batch_id'
                    )
                    ->lockForUpdate()
                    ->get();

            $available =
                (int) $batches->sum(
                    'current_quantity'
                );

            if ($available < $remaining) {
                throw ValidationException::withMessages([
                    'action' => [
                        "{$detail->product_name} needs {$remaining} unit(s), but only {$available} are currently available.",
                    ],
                ]);
            }

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min(
                    $remaining,
                    (int) $batch->current_quantity
                );

                if ($take <= 0) {
                    continue;
                }

                DB::table('WBO_Batches')
                    ->where(
                        'batch_id',
                        $batch->batch_id
                    )
                    ->update([
                        'current_quantity' =>
                            (int) $batch->current_quantity -
                            $take,
                    ]);

                DB::table(
                    'WBO_Transactions'
                )->insert([
                    'batch_id' =>
                        $batch->batch_id,
                    'transaction_type' =>
                        'RESERVE',
                    'quantity_change' =>
                        -$take,
                    'order_id' =>
                        $orderId,
                    'purchase_order_id' =>
                        null,
                    'reference_note' =>
                        "Reserved for order #{$orderId}",
                    'performed_by_user_id' =>
                        (int) session('user_id'),
                    'timestamp' =>
                        now(),
                ]);

                $remaining -= $take;
                $totalReserved += $take;
            }
        }

        return $totalReserved;
    }

    private function releaseReservations(
        int $orderId,
        string $reason
    ): void {
        $reservations =
            DB::table('WBO_Transactions')
                ->where(
                    'order_id',
                    $orderId
                )
                ->where(
                    'transaction_type',
                    'RESERVE'
                )
                ->orderBy(
                    'transaction_id'
                )
                ->get();

        foreach ($reservations as $reservation) {
            $releaseQuantity =
                abs(
                    (int)
                        $reservation
                            ->quantity_change
                );

            if ($releaseQuantity <= 0) {
                continue;
            }

            $batch =
                DB::table('WBO_Batches')
                    ->where(
                        'batch_id',
                        $reservation->batch_id
                    )
                    ->lockForUpdate()
                    ->first();

            if (!$batch) {
                throw ValidationException::withMessages([
                    'action' => [
                        'A reserved inventory batch no longer exists.',
                    ],
                ]);
            }

            DB::table('WBO_Batches')
                ->where(
                    'batch_id',
                    $reservation->batch_id
                )
                ->update([
                    'current_quantity' =>
                        (int) $batch->current_quantity +
                        $releaseQuantity,
                ]);

            /*
             * WBO_Transactions has no RELEASE enum. ADJUSTMENT records
             * the stock return without deleting the original reservation.
             */
            DB::table(
                'WBO_Transactions'
            )->insert([
                'batch_id' =>
                    $reservation->batch_id,
                'transaction_type' =>
                    'ADJUSTMENT',
                'quantity_change' =>
                    $releaseQuantity,
                'order_id' =>
                    $orderId,
                'purchase_order_id' =>
                    null,
                'reference_note' =>
                    "{$reason}; released reserved stock",
                'performed_by_user_id' =>
                    (int) session('user_id'),
                'timestamp' =>
                    now(),
            ]);
        }
    }

    private function authorizeRead(
        Request $request,
        string $role
    ): bool {
        if (
            !in_array(
                $role,
                self::SALES_ROLES,
                true
            )
        ) {
            abort(
                404,
                'This sales role dashboard does not exist.'
            );
        }

        if (
            session('logged_in') !== true
        ) {
            abort(
                401,
                'Authentication required.'
            );
        }

        $actualRole =
            (string) session('role');

        if ($actualRole === $role) {
            return false;
        }

        if (
            $actualRole ===
                'super_admin' &&
            $request->boolean('preview')
        ) {
            return true;
        }

        abort(
            403,
            'You are not authorized to view this sales dashboard.'
        );
    }

    private function authorizeAction(): string
    {
        if (
            session('logged_in') !== true
        ) {
            abort(
                401,
                'Authentication required.'
            );
        }

        $role =
            (string) session('role');

        if (
            !in_array(
                $role,
                self::SALES_ROLES,
                true
            )
        ) {
            abort(
                403,
                'This sales action is not allowed for your role.'
            );
        }

        return $role;
    }



    private function audit(
        Request $request,
        string $action,
        string $description
    ): void {
        try {
            DB::table(
                'WBO_AuditLogs'
            )->insert([
                'user_id' =>
                    (int)
                    session('user_id'),
                'action' =>
                    $action,
                'description' =>
                    $description,
                'ip_address' =>
                    $request->ip(),
                'user_agent' =>
                    mb_substr(
                        (string)
                        $request
                            ->userAgent(),
                        0,
                        500
                    ),
                'created_at' =>
                    now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function roleLabel(
        string $role
    ): string {
        return match ($role) {
            'Sales_Manager' =>
                'Sales Manager',
            'Sales_Staff' =>
                'Sales Staff',
            default =>
                $role,
        };
    }
}