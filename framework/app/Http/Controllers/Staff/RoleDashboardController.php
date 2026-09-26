<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Shared\NotificationService;
use App\Services\Staff\OperationalDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
 * WBO_ROLE_DASHBOARD_V2
 *
 * Live staff API for Operations, Warehouse, Inventory, and Purchasing.
 *
 * Authorization rules:
 * - Staff read only their own role dashboard.
 * - Super Admin reads role data only through preview=1.
 * - Preview is read-only because all write endpoints authorize the actual role.
 * - Operations Manager is monitoring-only in this API.
 * - Warehouse Admin: receiving / Stock In.
 * - Inventory Controller: Stock In + inventory adjustments.
 * - Purchasing Staff: supplier maintenance + PO preparation/submission.
 * - Purchasing Manager: supplier maintenance + PO creation/approval/order/cancel.
 */
/** Shared operational dashboard endpoints for operations, purchasing, warehouse, and inventory roles. */
class RoleDashboardController extends Controller
{
    private const LIVE_ROLES = [
        'Operations_Manager',
        'Purchasing_Manager',
        'Purchasing_Staff',
        'Warehouse_Admin',
        'Inventory_Controller',
    ];


    public function show(
        Request $request,
        string $role,
        NotificationService $notifications,
        OperationalDashboardService $dashboard
    ): JsonResponse {
        $isPreview = $this->authorizeRead($request, $role);

        if (!$isPreview) {
            $notifications->syncOperationalAlerts();
        }

        return response()->json(
            $dashboard->build($role, $isPreview)
        );
    }

    // =========================================================
    // WAREHOUSE / INVENTORY ACTIONS
    // =========================================================

    public function stockIn(
        Request $request,
        NotificationService $notifications
    ): JsonResponse {
        $role = $this->authorizeAction([
            'Warehouse_Admin',
            'Inventory_Controller',
        ]);

        $request->merge([
            'batch_number' =>
                trim((string) $request->input('batch_number', '')),
        ]);

        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('WBO_Products', 'product_id'),
            ],
            'batch_number' => [
                'required',
                'string',
                'max:50',
            ],
            'quantity_received' => [
                'required',
                'integer',
                'min:1',
            ],
            'expiry_date' => [
                'nullable',
                'date',
            ],
        ]);

        $duplicate = DB::table('WBO_Batches')
            ->where('product_id', $validated['product_id'])
            ->where('batch_number', $validated['batch_number'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'batch_number' => [
                    'That batch number already exists for the selected product.',
                ],
            ]);
        }

        $batchId = DB::transaction(
            function () use ($validated, $role) {
                $batchId = DB::table('WBO_Batches')
                    ->insertGetId([
                        'product_id' => $validated['product_id'],
                        'batch_number' => $validated['batch_number'],
                        'quantity_received' => $validated['quantity_received'],
                        'current_quantity' => $validated['quantity_received'],
                        'received_date' => now(),
                        'expiry_date' => $validated['expiry_date'] ?? null,
                    ]);

                DB::table('WBO_Transactions')->insert([
                    'batch_id' => $batchId,
                    'transaction_type' => 'RECEIVE',
                    'quantity_change' => $validated['quantity_received'],
                    'order_id' => null,
                    'purchase_order_id' => null,
                    'reference_note' =>
                        "Manual stock-in by {$this->roleLabel($role)}",
                    'performed_by_user_id' => (int) session('user_id'),
                    'timestamp' => now(),
                ]);

                return $batchId;
            }
        );

        $this->audit(
            $request,
            'STOCK_RECEIVED',
            sprintf(
                '%s stocked in batch %s with %d unit(s).',
                $this->roleLabel($role),
                $validated['batch_number'],
                $validated['quantity_received']
            )
        );

        $notifications->syncOperationalAlerts();

        return response()->json([
            'message' => 'Stock received successfully.',
            'batch_id' => $batchId,
        ], 201);
    }


    public function receivePurchaseOrder(
        Request $request,
        int $poId,
        NotificationService $notifications
    ): JsonResponse {
        $role = $this->authorizeAction([
            'Warehouse_Admin',
        ]);

        $request->merge([
            'batch_number' =>
                trim((string) $request->input('batch_number', '')),
        ]);

        $validated = $request->validate([
            'po_detail_id' => [
                'required',
                'integer',
                Rule::exists('WBO_PurchaseOrderDetails', 'po_detail_id'),
            ],
            'batch_number' => [
                'required',
                'string',
                'max:50',
            ],
            'quantity_received' => [
                'required',
                'integer',
                'min:1',
            ],
            'expiry_date' => [
                'nullable',
                'date',
            ],
        ]);

        $result = DB::transaction(
            function () use ($poId, $validated) {
                $purchaseOrder = DB::table('WBO_PurchaseOrders')
                    ->where('po_id', $poId)
                    ->lockForUpdate()
                    ->first();

                if (!$purchaseOrder) {
                    abort(404, 'Purchase order not found.');
                }

                if (
                    !in_array(
                        $purchaseOrder->status,
                        ['ORDERED', 'PARTIALLY_RECEIVED'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'purchase_order' => [
                            'Only ordered or partially received purchase orders can be received.',
                        ],
                    ]);
                }

                $detail = DB::table('WBO_PurchaseOrderDetails')
                    ->where('po_id', $poId)
                    ->where(
                        'po_detail_id',
                        $validated['po_detail_id']
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$detail) {
                    throw ValidationException::withMessages([
                        'po_detail_id' => [
                            'The selected purchase-order item was not found.',
                        ],
                    ]);
                }

                $remaining =
                    (int) $detail->quantity_ordered -
                    (int) $detail->quantity_received;

                if ($remaining <= 0) {
                    throw ValidationException::withMessages([
                        'quantity_received' => [
                            'This purchase-order item is already fully received.',
                        ],
                    ]);
                }

                if (
                    (int) $validated['quantity_received'] >
                    $remaining
                ) {
                    throw ValidationException::withMessages([
                        'quantity_received' => [
                            "Only {$remaining} unit(s) remain to be received for this item.",
                        ],
                    ]);
                }

                $duplicateBatch = DB::table('WBO_Batches')
                    ->where(
                        'product_id',
                        $detail->product_id
                    )
                    ->where(
                        'batch_number',
                        $validated['batch_number']
                    )
                    ->exists();

                if ($duplicateBatch) {
                    throw ValidationException::withMessages([
                        'batch_number' => [
                            'That batch number already exists for this product.',
                        ],
                    ]);
                }

                $batchId = DB::table('WBO_Batches')
                    ->insertGetId([
                        'product_id' =>
                            $detail->product_id,
                        'batch_number' =>
                            $validated['batch_number'],
                        'quantity_received' =>
                            $validated['quantity_received'],
                        'current_quantity' =>
                            $validated['quantity_received'],
                        'received_date' => now(),
                        'expiry_date' =>
                            $validated['expiry_date'] ?? null,
                    ]);

                DB::table('WBO_Transactions')->insert([
                    'batch_id' => $batchId,
                    'transaction_type' => 'RECEIVE',
                    'quantity_change' =>
                        $validated['quantity_received'],
                    'order_id' => null,
                    'purchase_order_id' => $poId,
                    'reference_note' =>
                        "Received from purchase order {$purchaseOrder->po_number}",
                    'performed_by_user_id' =>
                        (int) session('user_id'),
                    'timestamp' => now(),
                ]);

                $newReceived =
                    (int) $detail->quantity_received +
                    (int) $validated['quantity_received'];

                DB::table('WBO_PurchaseOrderDetails')
                    ->where(
                        'po_detail_id',
                        $detail->po_detail_id
                    )
                    ->update([
                        'quantity_received' => $newReceived,
                    ]);

                $hasRemainingItems =
                    DB::table('WBO_PurchaseOrderDetails')
                        ->where('po_id', $poId)
                        ->whereColumn(
                            'quantity_received',
                            '<',
                            'quantity_ordered'
                        )
                        ->exists();

                $newStatus =
                    $hasRemainingItems
                        ? 'PARTIALLY_RECEIVED'
                        : 'RECEIVED';

                DB::table('WBO_PurchaseOrders')
                    ->where('po_id', $poId)
                    ->update([
                        'status' => $newStatus,
                        'received_at' =>
                            $newStatus === 'RECEIVED'
                                ? now()
                                : null,
                    ]);

                return [
                    'po_number' =>
                        (string) $purchaseOrder->po_number,
                    'batch_id' => $batchId,
                    'new_status' => $newStatus,
                    'remaining_quantity' =>
                        max(
                            0,
                            (int) $detail->quantity_ordered -
                            $newReceived
                        ),
                ];
            }
        );

        $this->audit(
            $request,
            'PURCHASE_ORDER_RECEIVED',
            sprintf(
                '%s received %d unit(s) from %s into batch %s. New PO status: %s.',
                $this->roleLabel($role),
                $validated['quantity_received'],
                $result['po_number'],
                $validated['batch_number'],
                $result['new_status']
            )
        );

        $notifications->syncOperationalAlerts();

        return response()->json([
            'message' =>
                $result['new_status'] === 'RECEIVED'
                    ? 'Purchase order fully received and inventory updated.'
                    : 'Partial purchase-order delivery received and inventory updated.',
            'po_number' => $result['po_number'],
            'status' => $result['new_status'],
            'batch_id' => $result['batch_id'],
            'remaining_quantity' =>
                $result['remaining_quantity'],
        ], 201);
    }
    public function adjustStock(
        Request $request,
        NotificationService $notifications
    ): JsonResponse {
        $role = $this->authorizeAction([
            'Inventory_Controller',
        ]);

        $request->merge([
            'reference_note' =>
                trim((string) $request->input('reference_note', '')),
        ]);

        $validated = $request->validate([
            'batch_id' => [
                'required',
                'integer',
                Rule::exists('WBO_Batches', 'batch_id'),
            ],
            'quantity_change' => [
                'required',
                'integer',
                'not_in:0',
            ],
            'reference_note' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $batch = DB::table('WBO_Batches')
                ->where('batch_id', $validated['batch_id'])
                ->lockForUpdate()
                ->first();

            if (!$batch) {
                throw ValidationException::withMessages([
                    'batch_id' => [
                        'The selected batch no longer exists.',
                    ],
                ]);
            }

            $newQuantity =
                (int) $batch->current_quantity +
                (int) $validated['quantity_change'];

            if ($newQuantity < 0) {
                throw ValidationException::withMessages([
                    'quantity_change' => [
                        'The adjustment cannot make stock negative.',
                    ],
                ]);
            }

            DB::table('WBO_Batches')
                ->where('batch_id', $validated['batch_id'])
                ->update([
                    'current_quantity' => $newQuantity,
                ]);

            DB::table('WBO_Transactions')->insert([
                'batch_id' => $validated['batch_id'],
                'transaction_type' => 'ADJUSTMENT',
                'quantity_change' => $validated['quantity_change'],
                'order_id' => null,
                'purchase_order_id' => null,
                'reference_note' => $validated['reference_note'],
                'performed_by_user_id' => (int) session('user_id'),
                'timestamp' => now(),
            ]);

            return [
                'batch_number' => (string) $batch->batch_number,
                'new_quantity' => $newQuantity,
            ];
        });

        $this->audit(
            $request,
            'INVENTORY_ADJUSTED',
            sprintf(
                '%s adjusted batch %s by %+d unit(s). New quantity: %d. Reason: %s',
                $this->roleLabel($role),
                $result['batch_number'],
                $validated['quantity_change'],
                $result['new_quantity'],
                $validated['reference_note']
            )
        );

        $notifications->syncOperationalAlerts();

        return response()->json([
            'message' => 'Inventory adjustment saved.',
            'new_quantity' => $result['new_quantity'],
        ]);
    }

    // =========================================================
    // PURCHASING ACTIONS
    // =========================================================

    public function storeSupplier(Request $request): JsonResponse
    {
        $role = $this->authorizeAction([
            'Purchasing_Manager',
            'Purchasing_Staff',
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'lead_time_days' => ['required', 'integer', 'min:0'],
            'supplier_status' => [
                'nullable',
                Rule::in(['ACTIVE', 'INACTIVE']),
            ],
        ]);

        // Purchasing Staff may add operational supplier records but
        // may not create them already disabled.
        $supplierStatus =
            $role === 'Purchasing_Manager'
                ? ($validated['supplier_status'] ?? 'ACTIVE')
                : 'ACTIVE';

        $supplierId = DB::table('WBO_Suppliers')
            ->insertGetId([
                'name' => trim($validated['name']),
                'contact_number' =>
                    $validated['contact_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'lead_time_days' => $validated['lead_time_days'],
                'supplier_status' => $supplierStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->audit(
            $request,
            'SUPPLIER_ADDED',
            "{$this->roleLabel($role)} added supplier {$validated['name']}."
        );

        return response()->json([
            'message' => 'Supplier added successfully.',
            'supplier_id' => $supplierId,
        ], 201);
    }

    public function updateSupplier(
        Request $request,
        int $supplierId
    ): JsonResponse {
        $role = $this->authorizeAction([
            'Purchasing_Manager',
            'Purchasing_Staff',
        ]);

        $supplier = DB::table('WBO_Suppliers')
            ->where('supplier_id', $supplierId)
            ->first();

        if (!$supplier) {
            abort(404, 'Supplier not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'lead_time_days' => ['required', 'integer', 'min:0'],
            'supplier_status' => [
                'nullable',
                Rule::in(['ACTIVE', 'INACTIVE']),
            ],
        ]);

        // Only Purchasing Manager controls supplier activation state.
        $supplierStatus =
            $role === 'Purchasing_Manager'
                ? ($validated['supplier_status'] ?? $supplier->supplier_status)
                : $supplier->supplier_status;

        DB::table('WBO_Suppliers')
            ->where('supplier_id', $supplierId)
            ->update([
                'name' => trim($validated['name']),
                'contact_number' =>
                    $validated['contact_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'lead_time_days' => $validated['lead_time_days'],
                'supplier_status' => $supplierStatus,
                'updated_at' => now(),
            ]);

        $this->audit(
            $request,
            'SUPPLIER_UPDATED',
            "{$this->roleLabel($role)} updated supplier #{$supplierId} ({$validated['name']})."
        );

        return response()->json([
            'message' => 'Supplier updated successfully.',
        ]);
    }

    public function storePurchaseOrder(
        Request $request
    ): JsonResponse {
        $role = $this->authorizeAction([
            'Purchasing_Manager',
            'Purchasing_Staff',
        ]);

        $validated = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('WBO_Suppliers', 'supplier_id'),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('WBO_Products', 'product_id'),
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],
            'submit_for_approval' => [
                'nullable',
                'boolean',
            ],
        ]);

        $supplier = DB::table('WBO_Suppliers')
            ->where('supplier_id', $validated['supplier_id'])
            ->first();

        if (
            !$supplier ||
            $supplier->supplier_status !== 'ACTIVE'
        ) {
            throw ValidationException::withMessages([
                'supplier_id' => [
                    'Purchase orders require an active supplier.',
                ],
            ]);
        }

        $product = DB::table('WBO_Products')
            ->select(
                'product_id',
                'supplier_id',
                'name',
                'unit_cost'
            )
            ->where('product_id', $validated['product_id'])
            ->first();

        if (
            $product &&
            $product->supplier_id !== null &&
            (int) $product->supplier_id !==
                (int) $validated['supplier_id']
        ) {
            throw ValidationException::withMessages([
                'supplier_id' => [
                    'The selected supplier does not match the supplier assigned to this product.',
                ],
            ]);
        }

        $status =
            !empty($validated['submit_for_approval'])
                ? 'PENDING_APPROVAL'
                : 'DRAFT';

        $result = DB::transaction(
            function () use ($validated, $product, $status) {
                $poNumber =
                    $this->generatePurchaseOrderNumber();

                $poId = DB::table('WBO_PurchaseOrders')
                    ->insertGetId([
                        'po_number' => $poNumber,
                        'supplier_id' => $validated['supplier_id'],
                        'status' => $status,
                        'created_by_user_id' =>
                            (int) session('user_id'),
                        'approved_by_user_id' => null,
                        'created_at' => now(),
                        'approved_at' => null,
                        'ordered_at' => null,
                        'received_at' => null,
                        'cancelled_at' => null,
                    ]);

                DB::table('WBO_PurchaseOrderDetails')
                    ->insert([
                        'po_id' => $poId,
                        'product_id' => $validated['product_id'],
                        'quantity_ordered' => $validated['quantity'],
                        'quantity_received' => 0,
                        'unit_cost' =>
                            $product ? $product->unit_cost : 0,
                    ]);

                return [
                    'po_id' => $poId,
                    'po_number' => $poNumber,
                ];
            }
        );

        $this->audit(
            $request,
            'PURCHASE_ORDER_CREATED',
            sprintf(
                '%s created purchase order %s for %d unit(s) with status %s.',
                $this->roleLabel($role),
                $result['po_number'],
                $validated['quantity'],
                $status
            )
        );

        return response()->json([
            'message' =>
                $status === 'PENDING_APPROVAL'
                    ? 'Purchase order created and submitted for approval.'
                    : 'Purchase order draft created successfully.',
            'po_id' => $result['po_id'],
            'po_number' => $result['po_number'],
            'status' => $status,
        ], 201);
    }

    public function updatePurchaseOrderStatus(
        Request $request,
        int $poId
    ): JsonResponse {
        $role = $this->authorizeAction([
            'Purchasing_Manager',
            'Purchasing_Staff',
        ]);

        $validated = $request->validate([
            'action' => [
                'required',
                Rule::in([
                    'submit',
                    'approve',
                    'order',
                    'cancel',
                ]),
            ],
        ]);

        $purchaseOrder = DB::table('WBO_PurchaseOrders')
            ->where('po_id', $poId)
            ->first();

        if (!$purchaseOrder) {
            abort(404, 'Purchase order not found.');
        }

        $action = $validated['action'];
        $userId = (int) session('user_id');

        if ($action === 'submit') {
            if ($purchaseOrder->status !== 'DRAFT') {
                throw ValidationException::withMessages([
                    'action' => [
                        'Only draft purchase orders can be submitted for approval.',
                    ],
                ]);
            }

            if (
                $role === 'Purchasing_Staff' &&
                (int) $purchaseOrder->created_by_user_id !== $userId
            ) {
                abort(
                    403,
                    'Purchasing Staff may submit only purchase orders they created.'
                );
            }

            DB::table('WBO_PurchaseOrders')
                ->where('po_id', $poId)
                ->update([
                    'status' => 'PENDING_APPROVAL',
                ]);

            $message =
                'Purchase order submitted for approval.';
            $auditAction = 'PURCHASE_ORDER_SUBMITTED';
        } elseif ($action === 'approve') {
            $this->requirePurchasingManager($role);

            if (
                $purchaseOrder->status !==
                'PENDING_APPROVAL'
            ) {
                throw ValidationException::withMessages([
                    'action' => [
                        'Only purchase orders pending approval can be approved.',
                    ],
                ]);
            }

            DB::table('WBO_PurchaseOrders')
                ->where('po_id', $poId)
                ->update([
                    'status' => 'APPROVED',
                    'approved_by_user_id' => $userId,
                    'approved_at' => now(),
                ]);

            $message = 'Purchase order approved.';
            $auditAction = 'PURCHASE_ORDER_APPROVED';
        } elseif ($action === 'order') {
            $this->requirePurchasingManager($role);

            if ($purchaseOrder->status !== 'APPROVED') {
                throw ValidationException::withMessages([
                    'action' => [
                        'Only approved purchase orders can be marked ordered.',
                    ],
                ]);
            }

            DB::table('WBO_PurchaseOrders')
                ->where('po_id', $poId)
                ->update([
                    'status' => 'ORDERED',
                    'ordered_at' => now(),
                ]);

            $message =
                'Purchase order marked as ordered.';
            $auditAction = 'PURCHASE_ORDER_ORDERED';
        } else {
            $this->requirePurchasingManager($role);

            if (
                in_array(
                    $purchaseOrder->status,
                    [
                        'RECEIVED',
                        'CANCELLED',
                        'PARTIALLY_RECEIVED',
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'action' => [
                        'This purchase order can no longer be cancelled.',
                    ],
                ]);
            }

            DB::table('WBO_PurchaseOrders')
                ->where('po_id', $poId)
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                ]);

            $message = 'Purchase order cancelled.';
            $auditAction = 'PURCHASE_ORDER_CANCELLED';
        }

        $this->audit(
            $request,
            $auditAction,
            "{$this->roleLabel($role)} changed purchase order {$purchaseOrder->po_number}: {$message}"
        );

        return response()->json([
            'message' => $message,
        ]);
    }

    // =========================================================
    // AUTHORIZATION
    // =========================================================

    private function authorizeRead(
        Request $request,
        string $role
    ): bool {
        if (!in_array($role, self::LIVE_ROLES, true)) {
            abort(
                404,
                'This role dashboard is not available yet.'
            );
        }

        if (session('logged_in') !== true) {
            abort(401, 'Authentication required.');
        }

        $actualRole = (string) session('role');

        if ($actualRole === $role) {
            return false;
        }

        if (
            $actualRole === 'super_admin' &&
            $request->boolean('preview')
        ) {
            return true;
        }

        abort(
            403,
            'You are not authorized to view this role dashboard.'
        );
    }

    private function authorizeAction(
        array $allowedRoles
    ): string {
        if (session('logged_in') !== true) {
            abort(401, 'Authentication required.');
        }

        $actualRole = (string) session('role');

        if (
            !in_array(
                $actualRole,
                $allowedRoles,
                true
            )
        ) {
            abort(
                403,
                'This action is not allowed for your role.'
            );
        }

        return $actualRole;
    }

    private function requirePurchasingManager(
        string $role
    ): void {
        if ($role !== 'Purchasing_Manager') {
            abort(
                403,
                'This purchase-order decision requires Purchasing Manager access.'
            );
        }
    }

    // =========================================================
    // DATA
    // =========================================================



    // =========================================================
    // SUPPORT
    // =========================================================

    private function generatePurchaseOrderNumber(): string
    {
        do {
            $poNumber =
                'PO-' .
                now()->format('Ymd-His') .
                '-' .
                Str::upper(Str::random(4));
        } while (
            DB::table('WBO_PurchaseOrders')
                ->where('po_number', $poNumber)
                ->exists()
        );

        return $poNumber;
    }

    private function audit(
        Request $request,
        string $action,
        string $description
    ): void {
        try {
            DB::table('WBO_AuditLogs')->insert([
                'user_id' =>
                    (int) session('user_id'),
                'action' => $action,
                'description' => $description,
                'ip_address' => $request->ip(),
                'user_agent' =>
                    mb_substr(
                        (string) $request->userAgent(),
                        0,
                        500
                    ),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'Warehouse_Admin' =>
                'Warehouse Admin',
            'Inventory_Controller' =>
                'Inventory Controller',
            'Operations_Manager' =>
                'Operations Manager',
            'Purchasing_Manager' =>
                'Purchasing Manager',
            'Purchasing_Staff' =>
                'Purchasing Staff',
            default => $role,
        };
    }
}