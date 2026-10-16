<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Orders\Concerns\ReturnWorkflowHelpers;
use App\Models\WBOUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    use ReturnWorkflowHelpers;

    private const SALES_ROLES = [
        'Sales_Manager',
        'Sales_Staff',
    ];

    private const RETURN_ROLES = [
        'Sales_Manager',
        'Sales_Staff',
        'Warehouse_Admin',
        'Inventory_Controller',
        'Purchasing_Manager',
        'Purchasing_Staff',
    ];

    private const DISPOSITIONS = [
        'RESTOCK',
        'QUARANTINE',
        'WRITE_OFF',
        'RETURN_TO_SUPPLIER',
    ];

    private static ?bool $tablesReady = null;

    public function customerCreate(
        Request $request,
        int $orderId
    ): JsonResponse {
        $user = $this->customer($request);
        $this->ready();

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:500',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.order_detail_id' => [
                'required',
                'integer',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $order = DB::table('WBO_Orders')
            ->where('order_id', $orderId)
            ->where(
                'customer_user_id',
                $user->user_id
            )
            ->first();

        abort_unless($order, 404, 'Order not found.');

        if ($order->status !== 'FULFILLED') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Returns are available only for fulfilled orders.',
            ], 409);
        }

        $existing = DB::table('WBO_ReturnRequests')
            ->where('order_id', $orderId)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' =>
                    'A return request already exists for this order.',
            ], 409);
        }

        $details = DB::table(
            'WBO_OrderDetails as od'
        )
            ->join(
                'WBO_Products as p',
                'p.product_id',
                '=',
                'od.product_id'
            )
            ->where('od.order_id', $orderId)
            ->select(
                'od.*',
                'p.name as product_name'
            )
            ->get()
            ->keyBy('order_detail_id');

        $selected = collect($validated['items'])
            ->groupBy('order_detail_id')
            ->map(fn ($rows, $detailId) => [
                'order_detail_id' =>
                    (int) $detailId,
                'quantity' =>
                    (int) collect($rows)
                        ->sum('quantity'),
            ])
            ->values();

        $prepared = [];
        $refundAmount = 0.0;

        foreach ($selected as $item) {
            $detail = $details->get(
                $item['order_detail_id']
            );

            if (!$detail) {
                throw ValidationException::withMessages([
                    'items' => [
                        'One selected return item does not belong to this order.',
                    ],
                ]);
            }

            if (
                $item['quantity'] >
                (int) $detail->quantity
            ) {
                throw ValidationException::withMessages([
                    'items' => [
                        "{$detail->product_name} can return at most {$detail->quantity} unit(s).",
                    ],
                ]);
            }

            $prepared[] = [
                'order_detail_id' =>
                    (int) $detail->order_detail_id,
                'product_id' =>
                    (int) $detail->product_id,
                'quantity' =>
                    (int) $item['quantity'],
                'unit_price' =>
                    (float) $detail->unit_price,
            ];

            $refundAmount +=
                (float) $detail->unit_price *
                (int) $item['quantity'];
        }

        $returnId = DB::transaction(
            function () use (
                $orderId,
                $user,
                $validated,
                $prepared,
                $refundAmount
            ) {
                $id = DB::table(
                    'WBO_ReturnRequests'
                )->insertGetId([
                    'order_id' => $orderId,
                    'customer_user_id' =>
                        $user->user_id,
                    'status' => 'REQUESTED',
                    'reason' =>
                        trim($validated['reason']),
                    'inspection_disposition' =>
                        null,
                    'inspection_notes' => null,
                    'refund_amount' =>
                        $refundAmount,
                    'handled_by_user_id' => null,
                    'requested_at' => now(),
                    'approved_at' => null,
                    'received_at' => null,
                    'inspected_at' => null,
                    'refunded_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($prepared as $item) {
                    DB::table(
                        'WBO_ReturnItems'
                    )->insert([
                        'return_id' => $id,
                        'order_detail_id' =>
                            $item['order_detail_id'],
                        'product_id' =>
                            $item['product_id'],
                        'quantity' =>
                            $item['quantity'],
                        'unit_price' =>
                            $item['unit_price'],
                    ]);
                }

                return (int) $id;
            }
        );

        $this->notifySales(
            "Return request #{$returnId}",
            "{$user->name} requested a return for order #{$orderId}."
        );

        $this->audit(
            $request,
            (int) $user->user_id,
            'RETURN_REQUESTED',
            "Return #{$returnId} requested for order #{$orderId}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Return request submitted.',
            'return_request' =>
                $this->payload($returnId),
        ], 201);
    }

    public function staffIndex(
        Request $request
    ): JsonResponse {
        $access = $this->staffRead($request);
        $this->ready();

        $query = DB::table(
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
            ->select(
                'r.*',
                'o.total_amount as order_total',
                'u.name as customer_name',
                'u.email as customer_email',
                'h.name as handled_by_name'
            );

        $role = $access['role'];

        if ($role === 'Warehouse_Admin') {
            $query->whereIn(
                'r.status',
                [
                    'APPROVED',
                    'RECEIVED_FOR_INSPECTION',
                ]
            );
        } elseif ($role === 'Inventory_Controller') {
            $query->whereIn(
                'r.status',
                [
                    'RECEIVED_FOR_INSPECTION',
                    'REFUND_PENDING',
                    'REFUNDED',
                ]
            );
        } elseif (
            in_array(
                $role,
                [
                    'Purchasing_Manager',
                    'Purchasing_Staff',
                ],
                true
            )
        ) {
            $query->where(
                'r.inspection_disposition',
                'RETURN_TO_SUPPLIER'
            );
        }

        $rows = $query
            ->orderByRaw(
                "CASE r.status
                    WHEN 'REQUESTED' THEN 0
                    WHEN 'APPROVED' THEN 1
                    WHEN 'RECEIVED_FOR_INSPECTION' THEN 2
                    WHEN 'REFUND_PENDING' THEN 3
                    WHEN 'REFUNDED' THEN 4
                    ELSE 5
                END"
            )
            ->orderByDesc('r.updated_at')
            ->limit(100)
            ->get();

        $returnIds = $rows
            ->pluck('return_id')
            ->all();

        $items = $this->items($returnIds);

        return response()->json([
            'success' => true,
            'preview' => $access['preview'],
            'role' => $access['role'],
            'returns' => $rows
                ->map(
                    fn ($row) =>
                        $this->mapPayload(
                            $row,
                            $items
                        )
                )
                ->values(),
        ]);
    }

    public function staffUpdate(
        Request $request,
        int $returnId
    ): JsonResponse {
        $this->ready();

        $validated = $request->validate([
            'action' => [
                'required',
                Rule::in([
                    'approve',
                    'reject',
                    'receive',
                    'inspect',
                    'refund',
                ]),
            ],
            'disposition' => [
                'nullable',
                Rule::in(self::DISPOSITIONS),
            ],
            'inspection_notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $action = $validated['action'];
        $staff = $this->staffAction($action);

        DB::transaction(
            function () use (
                $returnId,
                $staff,
                $validated,
                $action
            ) {
                $row = DB::table(
                    'WBO_ReturnRequests'
                )
                    ->where(
                        'return_id',
                        $returnId
                    )
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $row,
                    404,
                    'Return request not found.'
                );

                if ($action === 'approve') {
                    $this->assertStatus(
                        $row,
                        'REQUESTED'
                    );

                    $this->updateReturn(
                        $returnId,
                        [
                            'status' => 'APPROVED',
                            'approved_at' => now(),
                            'handled_by_user_id' =>
                                $staff->user_id,
                        ]
                    );

                    return;
                }

                if ($action === 'reject') {
                    $this->assertStatus(
                        $row,
                        'REQUESTED'
                    );

                    $this->updateReturn(
                        $returnId,
                        [
                            'status' => 'REJECTED',
                            'handled_by_user_id' =>
                                $staff->user_id,
                        ]
                    );

                    return;
                }

                if ($action === 'receive') {
                    $this->assertStatus(
                        $row,
                        'APPROVED'
                    );

                    $this->updateReturn(
                        $returnId,
                        [
                            'status' =>
                                'RECEIVED_FOR_INSPECTION',
                            'received_at' => now(),
                            'handled_by_user_id' =>
                                $staff->user_id,
                        ]
                    );

                    return;
                }

                if ($action === 'inspect') {
                    $this->assertStatus(
                        $row,
                        'RECEIVED_FOR_INSPECTION'
                    );

                    $disposition =
                        $validated['disposition']
                        ?? null;

                    if (!$disposition) {
                        throw ValidationException::withMessages([
                            'disposition' => [
                                'Choose an inspection disposition.',
                            ],
                        ]);
                    }

                    if ($disposition === 'RESTOCK') {
                        $this->restock(
                            $row,
                            (int) $staff->user_id
                        );
                    }

                    $this->updateReturn(
                        $returnId,
                        [
                            'status' =>
                                'REFUND_PENDING',
                            'inspection_disposition' =>
                                $disposition,
                            'inspection_notes' =>
                                trim(
                                    (string) (
                                        $validated[
                                            'inspection_notes'
                                        ] ?? ''
                                    )
                                ) ?: null,
                            'inspected_at' => now(),
                            'handled_by_user_id' =>
                                $staff->user_id,
                        ]
                    );

                    return;
                }

                $this->assertStatus(
                    $row,
                    'REFUND_PENDING'
                );

                $this->updateReturn(
                    $returnId,
                    [
                        'status' => 'REFUNDED',
                        'refunded_at' => now(),
                        'handled_by_user_id' =>
                            $staff->user_id,
                    ]
                );

                $order = DB::table('WBO_Orders')
                    ->where(
                        'order_id',
                        $row->order_id
                    )
                    ->first();

                if (
                    $order &&
                    (float) $row->refund_amount >=
                    (float) $order->total_amount
                ) {
                    DB::table('WBO_Orders')
                        ->where(
                            'order_id',
                            $row->order_id
                        )
                        ->update([
                            'payment_status' =>
                                'REFUNDED',
                        ]);
                }
            }
        );

        $row = DB::table('WBO_ReturnRequests')
            ->where('return_id', $returnId)
            ->first();

        if ($row->status === 'APPROVED') {
            $this->notifyRoles(
                ['Warehouse_Admin'],
                "Approved return #{$returnId}",
                "Return #{$returnId} is approved and ready for warehouse receiving."
            );
        } elseif (
            $row->status ===
            'RECEIVED_FOR_INSPECTION'
        ) {
            $this->notifyRoles(
                ['Inventory_Controller'],
                "Return #{$returnId} received",
                "Return #{$returnId} is ready for inventory inspection."
            );
        } elseif (
            $row->status === 'REFUND_PENDING'
        ) {
            $this->notifyRoles(
                self::SALES_ROLES,
                "Return #{$returnId} inspected",
                "Return #{$returnId} is ready for refund processing."
            );

            if (
                $row->inspection_disposition ===
                'RETURN_TO_SUPPLIER'
            ) {
                $this->notifyRoles(
                    [
                        'Purchasing_Manager',
                        'Purchasing_Staff',
                    ],
                    "Supplier return #{$returnId}",
                    "Return #{$returnId} was marked Return to Supplier by Inventory Controller."
                );
            }
        }

        $this->notifyUser(
            (int) $row->customer_user_id,
            "Return #{$returnId} updated",
            "Your return for order #{$row->order_id} is now {$row->status}."
        );

        $this->audit(
            $request,
            (int) $staff->user_id,
            'RETURN_UPDATED',
            "Return #{$returnId} action {$action}; status {$row->status}."
        );

        return response()->json([
            'success' => true,
            'message' =>
                "Return #{$returnId} updated.",
            'return_request' =>
                $this->payload($returnId),
        ]);
    }
}