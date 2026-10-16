<?php

namespace App\Services\Imports;

use App\Services\Imports\Concerns\DataImportValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataImportService
{
    use DataImportValidation;

    private const MAX_ROWS = 500;
    private const PREVIEW_ROWS = 40;

    private array $productCache = [];
    private array $supplierCache = [];
    private array $batchCache = [];

    public function inspect(
        string $path,
        string $type
    ): array {
        $parsed = $this->parse($path, $type);
        $review = $this->reviewRows(
            $parsed['rows'],
            $type
        );

        return [
            'import_type' => $type,
            'headers' => $parsed['headers'],
            'required_headers' =>
                $this->requiredHeaders($type),
            'total_rows' => count(
                $parsed['rows']
            ),
            'valid_rows' =>
                $review['valid_rows'],
            'invalid_rows' =>
                $review['invalid_rows'],
            'rows' => array_slice(
                $review['rows'],
                0,
                self::PREVIEW_ROWS
            ),
        ];
    }

    public function run(
        string $path,
        string $type,
        int $userId,
        int $importId
    ): array {
        $parsed = $this->parse($path, $type);
        $review = $this->reviewRows(
            $parsed['rows'],
            $type
        );

        $successful = 0;
        $errors = [];

        foreach ($review['rows'] as $row) {
            if (!$row['valid']) {
                foreach ($row['errors'] as $error) {
                    $errors[] = [
                        'row_number' =>
                            $row['row_number'],
                        'sheet_name' =>
                            $parsed['sheet_name'],
                        'field_name' =>
                            $error['field'],
                        'raw_value' =>
                            $error['raw_value'],
                        'raw_row' =>
                            $row['data'],
                        'message' =>
                            $error['message'],
                    ];
                }

                continue;
            }

            try {
                DB::transaction(
                    function () use (
                        $type,
                        $row,
                        $userId,
                        $importId
                    ) {
                        $this->saveRow(
                            $type,
                            $row['data'],
                            $userId,
                            $importId
                        );
                    }
                );

                $successful++;
            } catch (\Throwable $exception) {
                report($exception);

                $errors[] = [
                    'row_number' =>
                        $row['row_number'],
                    'sheet_name' =>
                        $parsed['sheet_name'],
                    'field_name' => null,
                    'raw_value' => null,
                    'raw_row' =>
                        $row['data'],
                    'message' =>
                        'The row passed validation but could not be saved.',
                ];
            }
        }

        $failedRowNumbers = collect($errors)
            ->pluck('row_number')
            ->filter(fn ($value) => $value !== null)
            ->unique()
            ->count();

        return [
            'total_rows' =>
                count($parsed['rows']),
            'successful_rows' =>
                $successful,
            'failed_rows' =>
                $failedRowNumbers,
            'errors' => $errors,
        ];
    }

    private function saveRow(
        string $type,
        array $row,
        int $userId,
        int $importId
    ): void {
        match ($type) {
            'PRODUCTS' =>
                $this->saveProduct($row),
            'SUPPLIERS' =>
                $this->saveSupplier($row),
            'INVENTORY' =>
                $this->saveInventory(
                    $row,
                    $userId,
                    $importId
                ),
            default =>
                throw ValidationException::withMessages([
                    'import_type' => [
                        'Unsupported import type.',
                    ],
                ]),
        };
    }

    private function saveProduct(
        array $row
    ): void {
        $sku = trim((string) $row['sku']);
        $existing = DB::table('WBO_Products')
            ->where('sku', $sku)
            ->first();

        $categoryId = $this->categoryId(
            trim(
                (string)
                ($row['category'] ?? '')
            )
        );

        $supplierName = trim(
            (string)
            ($row['supplier'] ?? '')
        );
        $supplierId = $supplierName !== ''
            ? $this->supplierId($supplierName)
            : null;

        $values = [
            'name' =>
                trim((string) $row['name']),
            'description' =>
                $this->textOrNull(
                    $row['description'] ?? null
                ),
            'category_id' =>
                $categoryId,
            'supplier_id' =>
                $supplierId,
            'abc_class' =>
                strtoupper(
                    trim(
                        (string)
                        ($row['abc_class'] ?? '')
                    )
                ) ?: ($existing->abc_class ?? 'C'),
            'is_seasonal' =>
                $this->booleanOrDefault(
                    $row['is_seasonal'] ?? null,
                    (bool)
                    ($existing->is_seasonal ?? false)
                ),
            'is_visible' =>
                $this->booleanOrDefault(
                    $row['is_visible'] ?? null,
                    (bool)
                    ($existing->is_visible ?? true)
                ),
            'is_featured' =>
                $this->booleanOrDefault(
                    $row['is_featured'] ?? null,
                    (bool)
                    ($existing->is_featured ?? false)
                ),
            'unit_cost' =>
                (float) $row['unit_cost'],
            'unit_price' =>
                (float) $row['unit_price'],
            'reorder_point' =>
                !$this->blank(
                    $row['reorder_point'] ?? null
                )
                    ? (int)
                    $row['reorder_point']
                    : (int)
                    ($existing->reorder_point ?? 10),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('WBO_Products')
                ->where(
                    'product_id',
                    $existing->product_id
                )
                ->update($values);

            unset(
                $this->productCache[
                    mb_strtolower($sku)
                ]
            );

            return;
        }

        DB::table('WBO_Products')
            ->insert([
                'sku' => $sku,
                ...$values,
                'created_at' => now(),
            ]);

        unset(
            $this->productCache[
                mb_strtolower($sku)
            ]
        );
    }

    private function saveSupplier(
        array $row
    ): void {
        $name = trim((string) $row['name']);

        $existing = DB::table('WBO_Suppliers')
            ->where('name', $name)
            ->first();

        $values = [
            'contact_number' =>
                $this->textOrNull(
                    $row['contact_number'] ?? null
                ),
            'email' =>
                $this->textOrNull(
                    $row['email'] ?? null
                ),
            'address' =>
                $this->textOrNull(
                    $row['address'] ?? null
                ),
            'lead_time_days' =>
                !$this->blank(
                    $row['lead_time_days'] ?? null
                )
                    ? (int)
                    $row['lead_time_days']
                    : (int)
                    ($existing->lead_time_days ?? 7),
            'supplier_status' =>
                strtoupper(
                    trim(
                        (string)
                        ($row['supplier_status'] ?? '')
                    )
                ) ?: (
                    $existing->supplier_status
                    ?? 'ACTIVE'
                ),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('WBO_Suppliers')
                ->where(
                    'supplier_id',
                    $existing->supplier_id
                )
                ->update($values);
        } else {
            DB::table('WBO_Suppliers')
                ->insert([
                    'name' => $name,
                    ...$values,
                    'created_at' => now(),
                ]);
        }

        unset(
            $this->supplierCache[
                mb_strtolower($name)
            ]
        );
    }

    private function saveInventory(
        array $row,
        int $userId,
        int $importId
    ): void {
        $product = $this->product(
            trim((string) $row['sku'])
        );

        if (!$product) {
            throw ValidationException::withMessages([
                'sku' => [
                    'Product SKU no longer exists.',
                ],
            ]);
        }

        $batchNumber = trim(
            (string) $row['batch_number']
        );
        $quantity =
            (int) $row['quantity_received'];

        if (
            $this->batchExists(
                (int) $product->product_id,
                $batchNumber
            )
        ) {
            throw ValidationException::withMessages([
                'batch_number' => [
                    'Batch already exists.',
                ],
            ]);
        }

        $batchId = DB::table('WBO_Batches')
            ->insertGetId([
                'product_id' =>
                    $product->product_id,
                'batch_number' =>
                    $batchNumber,
                'quantity_received' =>
                    $quantity,
                'current_quantity' =>
                    $quantity,
                'received_date' =>
                    $this->dateTime(
                        $row['received_date']
                        ?? null
                    ) ?? now(),
                'expiry_date' =>
                    $this->date(
                        $row['expiry_date']
                        ?? null
                    ),
            ]);

        DB::table('WBO_Transactions')
            ->insert([
                'batch_id' => $batchId,
                'transaction_type' =>
                    'RECEIVE',
                'quantity_change' =>
                    $quantity,
                'order_id' => null,
                'purchase_order_id' => null,
                'reference_note' =>
                    "Data import #{$importId}; batch {$batchNumber}",
                'performed_by_user_id' =>
                    $userId,
                'timestamp' => now(),
            ]);

        $this->batchCache[
            $product->product_id
            . '|'
            . mb_strtolower($batchNumber)
        ] = true;
    }

    private function requiredHeaders(
        string $type
    ): array {
        return match ($type) {
            'PRODUCTS' => [
                'sku',
                'name',
                'unit_cost',
                'unit_price',
            ],
            'SUPPLIERS' => [
                'name',
            ],
            'INVENTORY' => [
                'sku',
                'batch_number',
                'quantity_received',
            ],
            default => [],
        };
    }

    private function rowKey(
        string $type,
        array $row
    ): ?string {
        return match ($type) {
            'PRODUCTS' =>
                $this->keyPart(
                    $row['sku'] ?? null
                ),
            'SUPPLIERS' =>
                $this->keyPart(
                    $row['name'] ?? null
                ),
            'INVENTORY' =>
                $this->inventoryKey($row),
            default => null,
        };
    }

    private function keyField(
        string $type
    ): string {
        return match ($type) {
            'PRODUCTS' => 'sku',
            'SUPPLIERS' => 'name',
            'INVENTORY' => 'batch_number',
            default => 'row',
        };
    }

    private function inventoryKey(
        array $row
    ): ?string {
        $sku = $this->keyPart(
            $row['sku'] ?? null
        );
        $batch = $this->keyPart(
            $row['batch_number'] ?? null
        );

        if (!$sku || !$batch) {
            return null;
        }

        return "{$sku}|{$batch}";
    }

    private function keyPart(
        mixed $value
    ): ?string {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : mb_strtolower($value);
    }

    private function actionFor(
        string $type,
        array $row
    ): string {
        return match ($type) {
            'PRODUCTS' =>
                $this->product(
                    trim(
                        (string) $row['sku']
                    )
                )
                    ? 'UPDATE'
                    : 'CREATE',
            'SUPPLIERS' =>
                $this->supplierId(
                    trim(
                        (string) $row['name']
                    )
                )
                    ? 'UPDATE'
                    : 'CREATE',
            'INVENTORY' => 'RECEIVE',
            default => '-',
        };
    }

    private function product(
        string $sku
    ): ?object {
        $key = mb_strtolower($sku);

        if (
            array_key_exists(
                $key,
                $this->productCache
            )
        ) {
            return $this->productCache[$key];
        }

        $product = DB::table('WBO_Products')
            ->where('sku', $sku)
            ->first();

        $this->productCache[$key] =
            $product;

        return $product;
    }

    private function supplierId(
        string $name
    ): ?int {
        $key = mb_strtolower($name);

        if (
            array_key_exists(
                $key,
                $this->supplierCache
            )
        ) {
            return $this->supplierCache[$key];
        }

        $id = DB::table('WBO_Suppliers')
            ->where('name', $name)
            ->value('supplier_id');

        $this->supplierCache[$key] =
            $id !== null
                ? (int) $id
                : null;

        return $this->supplierCache[$key];
    }

    private function categoryId(
        string $name
    ): ?int {
        if ($name === '') {
            return null;
        }

        $existing = DB::table('WBO_Categories')
            ->where('name', $name)
            ->value('category_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table(
            'WBO_Categories'
        )->insertGetId([
            'name' => $name,
            'description' =>
                'Created by data import.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function batchExists(
        int $productId,
        string $batch
    ): bool {
        $key =
            $productId
            . '|'
            . mb_strtolower($batch);

        if (
            array_key_exists(
                $key,
                $this->batchCache
            )
        ) {
            return $this->batchCache[$key];
        }

        $exists = DB::table('WBO_Batches')
            ->where(
                'product_id',
                $productId
            )
            ->where(
                'batch_number',
                $batch
            )
            ->exists();

        $this->batchCache[$key] =
            $exists;

        return $exists;
    }

}
