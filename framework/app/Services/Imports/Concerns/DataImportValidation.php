<?php

namespace App\Services\Imports\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

trait DataImportValidation
{
    private function parse(
        string $path,
        string $type
    ): array {
        try {
            $spreadsheet =
                IOFactory::load($path);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'file' => [
                    'The spreadsheet could not be read. Use a valid CSV, XLS, or XLSX file.',
                ],
            ]);
        }

        $sheet =
            $spreadsheet->getActiveSheet();

        $rawRows = $sheet->toArray(
            null,
            false,
            false,
            false
        );

        $headerIndex = null;
        $headers = [];

        foreach ($rawRows as $index => $cells) {
            if ($this->blankRow($cells)) {
                continue;
            }

            $headerIndex = $index;
            $headers = array_map(
                fn ($value) =>
                    $this->header($value),
                $cells
            );
            break;
        }

        if ($headerIndex === null) {
            throw ValidationException::withMessages([
                'file' => [
                    'The import file is empty.',
                ],
            ]);
        }

        $headers = array_values(
            array_map(
                fn ($header, $index) =>
                    $header !== ''
                        ? $header
                        : "_column_{$index}",
                $headers,
                array_keys($headers)
            )
        );

        $duplicates = collect($headers)
            ->duplicates()
            ->filter(
                fn ($header) =>
                    !str_starts_with(
                        $header,
                        '_column_'
                    )
            )
            ->values();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => [
                    'Duplicate header(s): '
                    . $duplicates->implode(', '),
                ],
            ]);
        }

        $missing = array_values(
            array_diff(
                $this->requiredHeaders($type),
                $headers
            )
        );

        if ($missing) {
            throw ValidationException::withMessages([
                'file' => [
                    'Missing required column(s): '
                    . implode(', ', $missing),
                ],
            ]);
        }

        $rows = [];

        for (
            $index = $headerIndex + 1;
            $index < count($rawRows);
            $index++
        ) {
            $cells = $rawRows[$index];

            if ($this->blankRow($cells)) {
                continue;
            }

            $data = [];

            foreach ($headers as $column => $header) {
                if (
                    str_starts_with(
                        $header,
                        '_column_'
                    )
                ) {
                    continue;
                }

                $data[$header] =
                    $this->clean(
                        $cells[$column] ?? null
                    );
            }

            $rows[] = [
                'row_number' =>
                    $index + 1,
                'data' => $data,
            ];
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => [
                    'An import may contain at most '
                    . self::MAX_ROWS
                    . ' data rows.',
                ],
            ]);
        }

        if (!$rows) {
            throw ValidationException::withMessages([
                'file' => [
                    'The file has headers but no data rows.',
                ],
            ]);
        }

        return [
            'headers' => array_values(
                array_filter(
                    $headers,
                    fn ($header) =>
                        !str_starts_with(
                            $header,
                            '_column_'
                        )
                )
            ),
            'sheet_name' =>
                $sheet->getTitle(),
            'rows' => $rows,
        ];
    }

    private function reviewRows(
        array $rows,
        string $type
    ): array {
        $reviewed = [];
        $valid = 0;
        $seen = [];

        foreach ($rows as $row) {
            $errors = $this->validateRow(
                $type,
                $row['data']
            );

            $key = $this->rowKey(
                $type,
                $row['data']
            );

            if (
                $key !== null &&
                isset($seen[$key])
            ) {
                $field = $this->keyField($type);

                $errors[] = $this->error(
                    $field,
                    $row['data'][$field] ?? null,
                    "Duplicate import key. First used on row {$seen[$key]}."
                );
            }

            if ($key !== null) {
                $seen[$key] =
                    $seen[$key]
                    ?? $row['row_number'];
            }

            $action =
                $errors
                    ? null
                    : $this->actionFor(
                        $type,
                        $row['data']
                    );

            $isValid = count($errors) === 0;

            if ($isValid) {
                $valid++;
            }

            $reviewed[] = [
                'row_number' =>
                    $row['row_number'],
                'valid' => $isValid,
                'action' => $action,
                'data' => $row['data'],
                'errors' => $errors,
            ];
        }

        return [
            'valid_rows' => $valid,
            'invalid_rows' =>
                count($reviewed) - $valid,
            'rows' => $reviewed,
        ];
    }

    private function validateRow(
        string $type,
        array $row
    ): array {
        return match ($type) {
            'PRODUCTS' =>
                $this->validateProduct($row),
            'SUPPLIERS' =>
                $this->validateSupplier($row),
            'INVENTORY' =>
                $this->validateInventory($row),
            default => [
                $this->error(
                    null,
                    null,
                    'Unsupported import type.'
                ),
            ],
        };
    }

    private function validateProduct(
        array $row
    ): array {
        $errors = [];

        $this->requiredText(
            $errors,
            $row,
            'sku',
            50
        );
        $this->requiredText(
            $errors,
            $row,
            'name',
            150
        );
        $this->requiredNumber(
            $errors,
            $row,
            'unit_cost',
            0
        );
        $this->requiredNumber(
            $errors,
            $row,
            'unit_price',
            0
        );

        $this->optionalText(
            $errors,
            $row,
            'category',
            100
        );
        $this->optionalText(
            $errors,
            $row,
            'supplier',
            150
        );

        $abc = strtoupper(
            trim(
                (string)
                ($row['abc_class'] ?? '')
            )
        );

        if (
            $abc !== '' &&
            !in_array(
                $abc,
                ['A', 'B', 'C'],
                true
            )
        ) {
            $errors[] = $this->error(
                'abc_class',
                $row['abc_class'] ?? null,
                'abc_class must be A, B, or C.'
            );
        }

        foreach (
            [
                'is_seasonal',
                'is_visible',
                'is_featured',
            ]
            as $field
        ) {
            $value = $row[$field] ?? null;

            if (
                !$this->blank($value) &&
                $this->boolean($value) === null
            ) {
                $errors[] = $this->error(
                    $field,
                    $value,
                    "{$field} must be 1/0, true/false, or yes/no."
                );
            }
        }

        $reorder = $row['reorder_point'] ?? null;

        if (
            !$this->blank($reorder) &&
            (
                filter_var(
                    $reorder,
                    FILTER_VALIDATE_INT
                ) === false ||
                (int) $reorder < 1
            )
        ) {
            $errors[] = $this->error(
                'reorder_point',
                $reorder,
                'reorder_point must be a whole number of at least 1.'
            );
        }

        $supplier = trim(
            (string)
            ($row['supplier'] ?? '')
        );

        if (
            $supplier !== '' &&
            $this->supplierId($supplier) === null
        ) {
            $errors[] = $this->error(
                'supplier',
                $supplier,
                'Supplier does not exist. Import suppliers first or leave supplier blank.'
            );
        }

        return $errors;
    }

    private function validateSupplier(
        array $row
    ): array {
        $errors = [];

        $this->requiredText(
            $errors,
            $row,
            'name',
            150
        );
        $this->optionalText(
            $errors,
            $row,
            'contact_number',
            20
        );
        $this->optionalText(
            $errors,
            $row,
            'address',
            255
        );

        $email = trim(
            (string) ($row['email'] ?? '')
        );

        if (
            $email !== '' &&
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $errors[] = $this->error(
                'email',
                $email,
                'email must be a valid email address.'
            );
        }

        $lead = $row['lead_time_days'] ?? null;

        if (
            !$this->blank($lead) &&
            (
                filter_var(
                    $lead,
                    FILTER_VALIDATE_INT
                ) === false ||
                (int) $lead < 0
            )
        ) {
            $errors[] = $this->error(
                'lead_time_days',
                $lead,
                'lead_time_days must be a whole number of 0 or more.'
            );
        }

        $status = strtoupper(
            trim(
                (string)
                ($row['supplier_status'] ?? '')
            )
        );

        if (
            $status !== '' &&
            !in_array(
                $status,
                ['ACTIVE', 'INACTIVE'],
                true
            )
        ) {
            $errors[] = $this->error(
                'supplier_status',
                $row['supplier_status'] ?? null,
                'supplier_status must be ACTIVE or INACTIVE.'
            );
        }

        return $errors;
    }

    private function validateInventory(
        array $row
    ): array {
        $errors = [];

        $this->requiredText(
            $errors,
            $row,
            'sku',
            50
        );
        $this->requiredText(
            $errors,
            $row,
            'batch_number',
            50
        );

        $quantity =
            $row['quantity_received']
            ?? null;

        if (
            filter_var(
                $quantity,
                FILTER_VALIDATE_INT
            ) === false ||
            (int) $quantity < 1
        ) {
            $errors[] = $this->error(
                'quantity_received',
                $quantity,
                'quantity_received must be a positive whole number.'
            );
        }

        $sku = trim(
            (string) ($row['sku'] ?? '')
        );
        $product = $sku !== ''
            ? $this->product($sku)
            : null;

        if ($sku !== '' && !$product) {
            $errors[] = $this->error(
                'sku',
                $sku,
                'Product SKU does not exist.'
            );
        }

        $batch = trim(
            (string)
            ($row['batch_number'] ?? '')
        );

        if (
            $product &&
            $batch !== '' &&
            $this->batchExists(
                (int) $product->product_id,
                $batch
            )
        ) {
            $errors[] = $this->error(
                'batch_number',
                $batch,
                'This product and batch number already exists. Use Stock In for additional quantity.'
            );
        }

        foreach (
            ['received_date', 'expiry_date']
            as $field
        ) {
            $value = $row[$field] ?? null;

            if (
                !$this->blank($value) &&
                $this->date($value) === null
            ) {
                $errors[] = $this->error(
                    $field,
                    $value,
                    "{$field} must be a valid date."
                );
            }
        }

        $expiry = $this->date(
            $row['expiry_date'] ?? null
        );

        if (
            $expiry !== null &&
            $expiry < now()->toDateString()
        ) {
            $errors[] = $this->error(
                'expiry_date',
                $row['expiry_date'] ?? null,
                'expiry_date cannot be before today.'
            );
        }

        return $errors;
    }

    private function requiredText(
        array &$errors,
        array $row,
        string $field,
        int $max
    ): void {
        $value = trim(
            (string) ($row[$field] ?? '')
        );

        if ($value === '') {
            $errors[] = $this->error(
                $field,
                $row[$field] ?? null,
                "{$field} is required."
            );
            return;
        }

        if (mb_strlen($value) > $max) {
            $errors[] = $this->error(
                $field,
                $value,
                "{$field} may not exceed {$max} characters."
            );
        }
    }

    private function optionalText(
        array &$errors,
        array $row,
        string $field,
        int $max
    ): void {
        $value = trim(
            (string) ($row[$field] ?? '')
        );

        if (
            $value !== '' &&
            mb_strlen($value) > $max
        ) {
            $errors[] = $this->error(
                $field,
                $value,
                "{$field} may not exceed {$max} characters."
            );
        }
    }

    private function requiredNumber(
        array &$errors,
        array $row,
        string $field,
        float $min
    ): void {
        $value = $row[$field] ?? null;

        if (
            $this->blank($value) ||
            !is_numeric($value) ||
            (float) $value < $min
        ) {
            $errors[] = $this->error(
                $field,
                $value,
                "{$field} must be a number of at least {$min}."
            );
        }
    }

    private function error(
        ?string $field,
        mixed $rawValue,
        string $message
    ): array {
        return [
            'field' => $field,
            'raw_value' =>
                $rawValue === null
                    ? null
                    : (string) $rawValue,
            'message' => $message,
        ];
    }

    private function header(
        mixed $value
    ): string {
        $header = trim(
            (string) $value
        );
        $header = ltrim(
            $header,
            "\xEF\xBB\xBF"
        );
        $header = mb_strtolower($header);
        $header = preg_replace(
            '/[^a-z0-9]+/u',
            '_',
            $header
        ) ?? '';

        return trim($header, '_');
    }

    private function clean(
        mixed $value
    ): mixed {
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function blankRow(
        array $cells
    ): bool {
        foreach ($cells as $cell) {
            if (!$this->blank($cell)) {
                return false;
            }
        }

        return true;
    }

    private function blank(
        mixed $value
    ): bool {
        return $value === null ||
            (
                is_string($value) &&
                trim($value) === ''
            );
    }

    private function boolean(
        mixed $value
    ): ?bool {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(
            trim((string) $value)
        );

        return match ($normalized) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function booleanOrDefault(
        mixed $value,
        bool $default
    ): bool {
        if ($this->blank($value)) {
            return $default;
        }

        return $this->boolean($value)
            ?? $default;
    }

    private function textOrNull(
        mixed $value
    ): ?string {
        $text = trim((string) $value);

        return $text === ''
            ? null
            : $text;
    }

    private function date(
        mixed $value
    ): ?string {
        if ($this->blank($value)) {
            return null;
        }

        try {
            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {
                return ExcelDate
                    ::excelToDateTimeObject(
                        (float) $value
                    )
                    ->format('Y-m-d');
            }

            return Carbon::parse(
                (string) $value
            )->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateTime(
        mixed $value
    ): ?string {
        if ($this->blank($value)) {
            return null;
        }

        try {
            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {
                return ExcelDate
                    ::excelToDateTimeObject(
                        (float) $value
                    )
                    ->format('Y-m-d H:i:s');
            }

            return Carbon::parse(
                (string) $value
            )->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }}
