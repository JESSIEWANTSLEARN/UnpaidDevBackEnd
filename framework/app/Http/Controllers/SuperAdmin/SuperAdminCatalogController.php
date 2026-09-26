<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HandlesSuperAdminSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuperAdminCatalogController extends Controller
{
    use HandlesSuperAdminSupport;

    public function storeProduct(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:50', Rule::unique('WBO_Products', 'sku')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', Rule::exists('WBO_Categories', 'category_id')],
            'category' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('WBO_Suppliers', 'supplier_id')],
            'abc_class' => ['required', Rule::in(['A', 'B', 'C'])],
            'is_seasonal' => ['required', 'boolean'],
            'is_visible' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $categoryId = $this->resolveCategoryId($validated);
        $imageValues = $this->productImageValues($request);

        $productId = DB::transaction(function () use ($validated, $categoryId, $imageValues) {
            $productId = DB::table('WBO_Products')->insertGetId([
                'sku' => $validated['sku'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'category_id' => $categoryId,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'abc_class' => $validated['abc_class'],
                'is_seasonal' => (bool) $validated['is_seasonal'],
                'is_visible' => (bool) $validated['is_visible'],
                'is_featured' => (bool) $validated['is_featured'],
                'unit_cost' => $validated['unit_cost'],
                'unit_price' => $validated['unit_price'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($imageValues) {
                DB::table('WBO_ProductImages')->insert([
                    'product_id' => $productId,
                    'image_path' => null,
                    'image_data' => $imageValues['image_data'],
                    'mime_type' => $imageValues['mime_type'],
                    'file_size' => $imageValues['file_size'],
                    'alt_text' => $validated['name'],
                    'is_primary' => true,
                    'sort_order' => 0,
                    'uploaded_by' => session('user_id'),
                    'created_at' => now(),
                ]);
            }

            return $productId;
        });

        $this->audit(
            $request,
            'PRODUCT_ADDED',
            "Added product {$validated['sku']} ({$validated['name']})."
        );

        return response()->json([
            'message' => 'Product added successfully.',
            'product_id' => $productId,
        ], 201);
    }

    public function updateProduct(
        Request $request,
        int $productId
    ): JsonResponse {
        $this->authorizeSuperAdmin();

        $product = DB::table('WBO_Products')
            ->where('product_id', $productId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validated = $request->validate([
            'sku' => [
                'required',
                'string',
                'max:50',
                Rule::unique('WBO_Products', 'sku')
                    ->ignore($productId, 'product_id'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('WBO_Categories', 'category_id'),
            ],
            'category' => ['nullable', 'string', 'max:100'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('WBO_Suppliers', 'supplier_id'),
            ],
            'abc_class' => ['required', Rule::in(['A', 'B', 'C'])],
            'is_seasonal' => ['required', 'boolean'],
            'is_visible' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $categoryId = $this->resolveCategoryId($validated);
        $newImageValues = $this->productImageValues($request);

        $oldImagePath = null;

        DB::transaction(function () use (
            $validated,
            $categoryId,
            $newImageValues,
            $productId,
            &$oldImagePath
        ) {
            DB::table('WBO_Products')
                ->where('product_id', $productId)
                ->update([
                    'sku' => $validated['sku'],
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'category_id' => $categoryId,
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'abc_class' => $validated['abc_class'],
                    'is_seasonal' => (bool) $validated['is_seasonal'],
                    'is_visible' => (bool) $validated['is_visible'],
                    'is_featured' => (bool) $validated['is_featured'],
                    'unit_cost' => $validated['unit_cost'],
                    'unit_price' => $validated['unit_price'],
                    'updated_at' => now(),
                ]);

            if (!$newImageValues) {
                return;
            }

            $primaryImage = DB::table('WBO_ProductImages')
                ->select('image_id', 'image_path')
                ->where('product_id', $productId)
                ->where('is_primary', true)
                ->orderBy('sort_order')
                ->first();

            if ($primaryImage) {
                $oldImagePath = $primaryImage->image_path;

                DB::table('WBO_ProductImages')
                    ->where('image_id', $primaryImage->image_id)
                    ->update([
                        'image_path' => null,
                        'image_data' => $newImageValues['image_data'],
                        'mime_type' => $newImageValues['mime_type'],
                        'file_size' => $newImageValues['file_size'],
                        'alt_text' => $validated['name'],
                        'uploaded_by' => session('user_id'),
                    ]);
            } else {
                DB::table('WBO_ProductImages')->insert([
                    'product_id' => $productId,
                    'image_path' => null,
                    'image_data' => $newImageValues['image_data'],
                    'mime_type' => $newImageValues['mime_type'],
                    'file_size' => $newImageValues['file_size'],
                    'alt_text' => $validated['name'],
                    'is_primary' => true,
                    'sort_order' => 0,
                    'uploaded_by' => session('user_id'),
                    'created_at' => now(),
                ]);
            }
        });

        if (
            $oldImagePath
            && Storage::disk('public')->exists($oldImagePath)
        ) {
            Storage::disk('public')->delete($oldImagePath);
        }

        $this->audit(
            $request,
            'PRODUCT_UPDATED',
            "Updated product #{$productId} {$validated['sku']} ({$validated['name']})."
        );

        return response()->json([
            'message' => 'Product updated successfully.',
            'product_id' => $productId,
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $request->merge([
            'name' => trim((string) $request->input('name', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('WBO_Categories', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryId = DB::table('WBO_Categories')->insertGetId([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit(
            $request,
            'CATEGORY_ADDED',
            "Added category {$validated['name']}."
        );

        return response()->json([
            'message' => 'Category added successfully.',
            'category_id' => $categoryId,
            'category' => [
                'category_id' => $categoryId,
                'category' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ],
        ], 201);
    }


    public function updateCategory(
        Request $request,
        int $categoryId
    ): JsonResponse {
        $this->authorizeSuperAdmin();

        $category = DB::table('WBO_Categories')
            ->where('category_id', $categoryId)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Category not found.',
            ], 404);
        }

        $request->merge([
            'name' => trim((string) $request->input('name', '')),
        ]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('WBO_Categories', 'name')
                    ->ignore($categoryId, 'category_id'),
            ],
            'description' => [
                'nullable',
                'string',
                'max:255',
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        DB::table('WBO_Categories')
            ->where('category_id', $categoryId)
            ->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) $validated['is_active'],
                'updated_at' => now(),
            ]);

        $this->audit(
            $request,
            'CATEGORY_UPDATED',
            "Updated category #{$categoryId} ({$validated['name']})."
        );

        return response()->json([
            'message' => 'Category updated successfully.',
        ]);
    }
    public function stockIn(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('WBO_Products', 'product_id')],
            'batch_number' => ['required', 'string', 'max:50'],
            'quantity_received' => ['required', 'integer', 'min:1'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $duplicate = DB::table('WBO_Batches')
            ->where('product_id', $validated['product_id'])
            ->where('batch_number', $validated['batch_number'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'batch_number' => ['That batch number already exists for the selected product.'],
            ]);
        }

        $batchId = DB::transaction(function () use ($validated) {
            $batchId = DB::table('WBO_Batches')->insertGetId([
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
                'reference_note' => 'Manual stock-in by Super Admin',
                'performed_by_user_id' => session('user_id'),
                'timestamp' => now(),
            ]);

            return $batchId;
        });

        $this->audit(
            $request,
            'STOCK_RECEIVED',
            "Stocked in batch {$validated['batch_number']} with {$validated['quantity_received']} unit(s)."
        );

        return response()->json([
            'message' => 'Stock received successfully.',
            'batch_id' => $batchId,
        ], 201);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'lead_time_days' => ['required', 'integer', 'min:0'],
            'supplier_status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $supplierId = DB::table('WBO_Suppliers')->insertGetId([
            'name' => $validated['name'],
            'contact_number' => $validated['contact_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'lead_time_days' => $validated['lead_time_days'],
            'supplier_status' => $validated['supplier_status'] ?? 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit(
            $request,
            'SUPPLIER_ADDED',
            "Added supplier {$validated['name']}."
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
        $this->authorizeSuperAdmin();

        $supplier = DB::table('WBO_Suppliers')
            ->where('supplier_id', $supplierId)
            ->first();

        if (!$supplier) {
            return response()->json([
                'message' => 'Supplier not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'contact_number' => [
                'nullable',
                'string',
                'max:20',
            ],
            'email' => [
                'nullable',
                'email',
                'max:100',
            ],
            'address' => [
                'nullable',
                'string',
                'max:255',
            ],
            'lead_time_days' => [
                'required',
                'integer',
                'min:0',
            ],
            'supplier_status' => [
                'required',
                Rule::in(['ACTIVE', 'INACTIVE']),
            ],
        ]);

        DB::table('WBO_Suppliers')
            ->where('supplier_id', $supplierId)
            ->update([
                'name' => trim($validated['name']),
                'contact_number' => $validated['contact_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'lead_time_days' => $validated['lead_time_days'],
                'supplier_status' => $validated['supplier_status'],
                'updated_at' => now(),
            ]);

        $this->audit(
            $request,
            'SUPPLIER_UPDATED',
            "Updated supplier #{$supplierId} ({$validated['name']})."
        );

        return response()->json([
            'message' => 'Supplier updated successfully.',
        ]);
    }
    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('WBO_Suppliers', 'supplier_id')],
            'product_id' => ['required', 'integer', Rule::exists('WBO_Products', 'product_id')],
            'quantity' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['DRAFT', 'ORDERED'])],
        ]);

        $product = DB::table('WBO_Products')
            ->select('product_id', 'supplier_id', 'name', 'unit_cost')
            ->where('product_id', $validated['product_id'])
            ->first();

        if (
            $product &&
            $product->supplier_id !== null &&
            (int) $product->supplier_id !== (int) $validated['supplier_id']
        ) {
            throw ValidationException::withMessages([
                'supplier_id' => ['The selected supplier does not match the supplier assigned to this product.'],
            ]);
        }

        $result = DB::transaction(function () use ($validated, $product) {
            $poNumber = $this->generatePurchaseOrderNumber();

            $poId = DB::table('WBO_PurchaseOrders')->insertGetId([
                'po_number' => $poNumber,
                'supplier_id' => $validated['supplier_id'],
                'status' => $validated['status'],
                'created_by_user_id' => session('user_id'),
                'approved_by_user_id' => null,
                'created_at' => now(),
                'ordered_at' => $validated['status'] === 'ORDERED' ? now() : null,
            ]);

            DB::table('WBO_PurchaseOrderDetails')->insert([
                'po_id' => $poId,
                'product_id' => $validated['product_id'],
                'quantity_ordered' => $validated['quantity'],
                'quantity_received' => 0,
                'unit_cost' => $product ? $product->unit_cost : 0,
            ]);

            return [
                'po_id' => $poId,
                'po_number' => $poNumber,
            ];
        });

        $this->audit(
            $request,
            'PURCHASE_ORDER_CREATED',
            "Created purchase order {$result['po_number']} for {$validated['quantity']} unit(s)."
        );

        return response()->json([
            'message' => 'Purchase order created successfully.',
            'po_id' => $result['po_id'],
            'po_number' => $result['po_number'],
        ], 201);
    }

    private function productImageValues(Request $request): ?array
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');
        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            throw ValidationException::withMessages([
                'image' => ['Unable to read the selected product image.'],
            ]);
        }

        return [
            'image_data' => $bytes,
            'mime_type' => $file->getMimeType()
                ?: $file->getClientMimeType()
                ?: 'application/octet-stream',
            'file_size' => strlen($bytes),
        ];
    }

    private function resolveCategoryId(array $validated): ?int
    {
        if (!empty($validated['category_id'])) {
            return (int) $validated['category_id'];
        }

        $categoryName = trim((string) ($validated['category'] ?? ''));

        if ($categoryName === '') {
            return null;
        }

        $categoryId = DB::table('WBO_Categories')
            ->where('name', $categoryName)
            ->value('category_id');

        if (!$categoryId) {
            throw ValidationException::withMessages([
                'category' => ['Select an existing category from WBO_Categories.'],
            ]);
        }

        return (int) $categoryId;
    }

    private function generatePurchaseOrderNumber(): string
    {
        do {
            $poNumber = 'PO-' . now()->format('Ymd-His') . '-' . Str::upper(Str::random(4));
        } while (DB::table('WBO_PurchaseOrders')->where('po_number', $poNumber)->exists());

        return $poNumber;
    }
}
