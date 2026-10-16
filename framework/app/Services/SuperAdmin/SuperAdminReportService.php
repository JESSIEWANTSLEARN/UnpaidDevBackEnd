<?php

namespace App\Services\SuperAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SuperAdminReportService
{
    private const LOW_STOCK_THRESHOLD = 10;

    private const TYPES = [
        'complete' => 'Complete System',
        'products' => 'Products / Catalog',
        'inventory' => 'Inventory',
        'sales' => 'Sales',
        'purchase_orders' => 'Purchase Orders',
        'suppliers' => 'Suppliers',
        'users' => 'Users',
        'audit_logs' => 'Audit Logs',
    ];

    public function supports(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    public function label(string $type): string
    {
        return self::TYPES[$type] ?? self::TYPES['complete'];
    }

    public function build(string $type): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('WalangBrownout')
            ->setTitle($this->label($type) . ' Report')
            ->setSubject('WalangBrownout Super Admin Report');

        $definitions = $this->definitions($type);

        foreach ($definitions as $index => $definition) {
            $sheet = $index === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet();

            $sheet->setTitle($definition['title']);

            $this->populateSheet(
                $sheet,
                $definition['title'],
                $definition['headers'],
                $definition['rows'],
                $definition['currency'] ?? [],
                $definition['integer'] ?? []
            );
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function write(Spreadsheet $spreadsheet): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
    }

    private function definitions(string $type): array
    {
        $all = [
            'summary' => [
                'title' => 'Summary',
                'headers' => ['Metric', 'Value'],
                'rows' => $this->summaryRows(),
                'integer' => [],
            ],
            'products' => [
                'title' => 'Products',
                'headers' => [
                    'Product ID', 'SKU', 'Name', 'Category', 'Supplier', 'ABC Class',
                    'Unit Cost', 'Unit Price', 'Stock', 'Reorder Point', 'Visible', 'Featured'
                ],
                'rows' => $this->productRows(),
                'currency' => ['Unit Cost', 'Unit Price'],
                'integer' => ['Product ID', 'Stock', 'Reorder Point'],
            ],
            'inventory' => [
                'title' => 'Inventory',
                'headers' => [
                    'Batch ID', 'SKU', 'Product', 'Category', 'Batch Number',
                    'Quantity Received', 'Current Quantity', 'Received Date', 'Expiry Date'
                ],
                'rows' => $this->inventoryRows(),
                'integer' => ['Batch ID', 'Quantity Received', 'Current Quantity'],
            ],
            'suppliers' => [
                'title' => 'Suppliers',
                'headers' => [
                    'Supplier ID', 'Name', 'Contact', 'Email', 'Address',
                    'Lead Time Days', 'Status', 'Products'
                ],
                'rows' => $this->supplierRows(),
                'integer' => ['Supplier ID', 'Lead Time Days', 'Products'],
            ],
            'purchase_orders' => [
                'title' => 'Purchase Orders',
                'headers' => [
                    'PO ID', 'PO Number', 'Supplier', 'SKU', 'Product',
                    'Quantity Ordered', 'Quantity Received', 'Unit Cost', 'Line Total',
                    'Status', 'Created By', 'Approved By', 'Created At',
                    'Approved At', 'Ordered At', 'Received At', 'Cancelled At'
                ],
                'rows' => $this->purchaseOrderRows(),
                'currency' => ['Unit Cost', 'Line Total'],
                'integer' => ['PO ID', 'Quantity Ordered', 'Quantity Received'],
            ],
            'sales' => [
                'title' => 'Sales Orders',
                'headers' => [
                    'Order ID', 'Customer User ID', 'Customer', 'Contact',
                    'SKU', 'Product', 'Quantity', 'Unit Price', 'Line Total',
                    'Status', 'Order Date', 'Fulfilled At', 'Cancelled At'
                ],
                'rows' => $this->salesRows(),
                'currency' => ['Unit Price', 'Line Total'],
                'integer' => ['Order ID', 'Customer User ID', 'Quantity'],
            ],
            'users' => [
                'title' => 'Users',
                'headers' => [
                    'User ID', 'Name', 'Email', 'Contact', 'Role', 'Status',
                    'Email Verified At', 'Last Seen At', 'Created At'
                ],
                'rows' => $this->userRows(),
                'integer' => ['User ID'],
            ],
            'audit_logs' => [
                'title' => 'Audit Logs',
                'headers' => [
                    'Log ID', 'User ID', 'User', 'Email', 'Role', 'Action',
                    'Description', 'IP Address', 'Created At'
                ],
                'rows' => $this->auditRows(),
                'integer' => ['Log ID', 'User ID'],
            ],
        ];

        if ($type === 'complete') {
            return [
                $all['summary'],
                $all['products'],
                $all['inventory'],
                $all['suppliers'],
                $all['purchase_orders'],
                $all['sales'],
                $all['users'],
                $all['audit_logs'],
            ];
        }

        return [$all[$type]];
    }

    private function summaryRows(): array
    {
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
            ->select('product_id', DB::raw('SUM(current_quantity) AS stock'))
            ->groupBy('product_id');

        $products = DB::table('WBO_Products as p')
            ->leftJoinSub($stockTotals, 'stock', function ($join) {
                $join->on('stock.product_id', '=', 'p.product_id');
            })
            ->select('p.product_id', 'p.reorder_point', DB::raw('COALESCE(stock.stock, 0) AS stock'))
            ->get();

        $monthStart = Carbon::now('Asia/Manila')->startOfMonth()->utc();

        $monthlyRevenue = (float) DB::table('WBO_Orders as o')
            ->join('WBO_OrderDetails as od', 'od.order_id', '=', 'o.order_id')
            ->where('o.status', 'FULFILLED')
            ->where('o.order_date', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(od.quantity * od.unit_price), 0) AS total')
            ->value('total');

        $monthlyOrders = DB::table('WBO_Orders')
            ->where('order_date', '>=', $monthStart)
            ->count();

        $productsSold = DB::table('WBO_Orders as o')
            ->join('WBO_OrderDetails as od', 'od.order_id', '=', 'o.order_id')
            ->where('o.status', 'FULFILLED')
            ->where('o.order_date', '>=', $monthStart)
            ->sum('od.quantity');

        $openPoStatuses = [
            'DRAFT',
            'PENDING_APPROVAL',
            'APPROVED',
            'ORDERED',
            'PARTIALLY_RECEIVED',
        ];

        return [
            ['Generated At', Carbon::now('Asia/Manila')->format('Y-m-d H:i:s') . ' Asia/Manila'],
            ['Total Products', $products->count()],
            ['Total Stock', (int) $products->sum('stock')],
            ['Low Stock Items', $products->filter(fn ($p) => (int) $p->stock > 0 && (int) $p->stock <= max(1, (int) $p->reorder_point))->count()],
            ['Out Of Stock', $products->filter(fn ($p) => (int) $p->stock <= 0)->count()],
            ['Total Suppliers', DB::table('WBO_Suppliers')->count()],
            ['Pending Sales Orders', DB::table('WBO_Orders')->where('status', 'PENDING')->count()],
            ['Total Users', DB::table('WBO_Users')->count()],
            ['Monthly Revenue', 'PHP ' . number_format($monthlyRevenue, 2)],
            ['Monthly Orders', $monthlyOrders],
            ['Products Sold', (int) $productsSold],
            ['Unread Notifications', DB::table('WBO_Notifications')->where('status', 'UNREAD')->count()],
            ['Open Purchase Orders', DB::table('WBO_PurchaseOrders')->whereIn('status', $openPoStatuses)->count()],
        ];
    }

    private function productRows(): array
    {
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
            ->select('product_id', DB::raw('SUM(current_quantity) AS stock'))
            ->groupBy('product_id');

        return DB::table('WBO_Products as p')
            ->leftJoin('WBO_Categories as c', 'c.category_id', '=', 'p.category_id')
            ->leftJoin('WBO_Suppliers as s', 's.supplier_id', '=', 'p.supplier_id')
            ->leftJoinSub($stockTotals, 'stock', function ($join) {
                $join->on('stock.product_id', '=', 'p.product_id');
            })
            ->select(
                'p.product_id',
                'p.sku',
                'p.name',
                'c.name as category',
                's.name as supplier',
                'p.abc_class',
                'p.unit_cost',
                'p.unit_price',
                DB::raw('COALESCE(stock.stock, 0) AS stock'),
                'p.reorder_point',
                'p.is_visible',
                'p.is_featured'
            )
            ->orderBy('p.product_id')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->product_id,
                $row->sku,
                $row->name,
                $row->category,
                $row->supplier,
                $row->abc_class,
                (float) $row->unit_cost,
                (float) $row->unit_price,
                (int) $row->stock,
                (int) $row->reorder_point,
                $row->is_visible ? 'Yes' : 'No',
                $row->is_featured ? 'Yes' : 'No',
            ]))
            ->all();
    }

    private function inventoryRows(): array
    {
        return DB::table('WBO_Batches as b')
            ->join('WBO_Products as p', 'p.product_id', '=', 'b.product_id')
            ->leftJoin('WBO_Categories as c', 'c.category_id', '=', 'p.category_id')
            ->select(
                'b.batch_id',
                'p.sku',
                'p.name as product_name',
                'c.name as category',
                'b.batch_number',
                'b.quantity_received',
                'b.current_quantity',
                'b.received_date',
                'b.expiry_date'
            )
            ->orderByDesc('b.received_date')
            ->orderByDesc('b.batch_id')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->batch_id,
                $row->sku,
                $row->product_name,
                $row->category,
                $row->batch_number,
                (int) $row->quantity_received,
                (int) $row->current_quantity,
                $this->manilaDate($row->received_date),
                $this->manilaDate($row->expiry_date, false),
            ]))
            ->all();
    }

    private function supplierRows(): array
    {
        return DB::table('WBO_Suppliers as s')
            ->leftJoin('WBO_Products as p', 'p.supplier_id', '=', 's.supplier_id')
            ->select(
                's.supplier_id',
                's.name',
                's.contact_number',
                's.email',
                's.address',
                's.lead_time_days',
                's.supplier_status',
                DB::raw('COUNT(p.product_id) AS product_count')
            )
            ->groupBy(
                's.supplier_id',
                's.name',
                's.contact_number',
                's.email',
                's.address',
                's.lead_time_days',
                's.supplier_status'
            )
            ->orderBy('s.name')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->supplier_id,
                $row->name,
                $row->contact_number,
                $row->email,
                $row->address,
                (int) $row->lead_time_days,
                $row->supplier_status,
                (int) $row->product_count,
            ]))
            ->all();
    }

    private function purchaseOrderRows(): array
    {
        return DB::table('WBO_PurchaseOrders as po')
            ->join('WBO_Suppliers as s', 's.supplier_id', '=', 'po.supplier_id')
            ->join('WBO_PurchaseOrderDetails as pod', 'pod.po_id', '=', 'po.po_id')
            ->join('WBO_Products as p', 'p.product_id', '=', 'pod.product_id')
            ->leftJoin('WBO_Users as creator', 'creator.user_id', '=', 'po.created_by_user_id')
            ->leftJoin('WBO_Users as approver', 'approver.user_id', '=', 'po.approved_by_user_id')
            ->select(
                'po.po_id',
                'po.po_number',
                's.name as supplier_name',
                'p.sku',
                'p.name as product_name',
                'pod.quantity_ordered',
                'pod.quantity_received',
                'pod.unit_cost',
                'po.status',
                'creator.name as created_by',
                'approver.name as approved_by',
                'po.created_at',
                'po.approved_at',
                'po.ordered_at',
                'po.received_at',
                'po.cancelled_at'
            )
            ->orderByDesc('po.created_at')
            ->orderByDesc('po.po_id')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->po_id,
                $row->po_number,
                $row->supplier_name,
                $row->sku,
                $row->product_name,
                (int) $row->quantity_ordered,
                (int) $row->quantity_received,
                (float) $row->unit_cost,
                (float) $row->unit_cost * (int) $row->quantity_ordered,
                $row->status,
                $row->created_by,
                $row->approved_by,
                $this->manilaDate($row->created_at),
                $this->manilaDate($row->approved_at),
                $this->manilaDate($row->ordered_at),
                $this->manilaDate($row->received_at),
                $this->manilaDate($row->cancelled_at),
            ]))
            ->all();
    }

    private function salesRows(): array
    {
        return DB::table('WBO_Orders as o')
            ->join('WBO_OrderDetails as od', 'od.order_id', '=', 'o.order_id')
            ->join('WBO_Products as p', 'p.product_id', '=', 'od.product_id')
            ->select(
                'o.order_id',
                'o.customer_user_id',
                'o.customer_name',
                'o.customer_contact',
                'p.sku',
                'p.name as product_name',
                'od.quantity',
                'od.unit_price',
                'o.status',
                'o.order_date',
                'o.fulfilled_at',
                'o.cancelled_at'
            )
            ->orderByDesc('o.order_date')
            ->orderByDesc('o.order_id')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->order_id,
                $row->customer_user_id,
                $row->customer_name,
                $row->customer_contact,
                $row->sku,
                $row->product_name,
                (int) $row->quantity,
                (float) $row->unit_price,
                (float) $row->unit_price * (int) $row->quantity,
                $row->status,
                $this->manilaDate($row->order_date),
                $this->manilaDate($row->fulfilled_at),
                $this->manilaDate($row->cancelled_at),
            ]))
            ->all();
    }

    private function userRows(): array
    {
        return DB::table('WBO_Users')
            ->select(
                'user_id',
                'name',
                'email',
                'contact_number',
                'role',
                'account_status',
                'email_verified_at',
                'last_seen_at',
                'created_at'
            )
            ->orderBy('user_id')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->user_id,
                $row->name,
                $row->email,
                $row->contact_number,
                $row->role,
                $row->account_status,
                $this->manilaDate($row->email_verified_at),
                $this->manilaDate($row->last_seen_at),
                $this->manilaDate($row->created_at),
            ]))
            ->all();
    }

    private function auditRows(): array
    {
        return DB::table('WBO_AuditLogs as a')
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
                'a.created_at'
            )
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.log_id')
            ->get()
            ->map(fn ($row) => $this->cleanRow([
                $row->log_id,
                $row->user_id,
                $row->user_name ?: 'System',
                $row->user_email,
                $row->user_role ?: 'System',
                $row->action,
                $row->description,
                $row->ip_address,
                $this->manilaDate($row->created_at),
            ]))
            ->all();
    }

    private function populateSheet(
        Worksheet $sheet,
        string $title,
        array $headers,
        array $rows,
        array $currencyHeaders = [],
        array $integerHeaders = []
    ): void {
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $headerRow = 4;
        $firstDataRow = 5;
        $lastRow = max($headerRow, $headerRow + count($rows));

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', "WalangBrownout - {$title}");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue(
            'A2',
            'Generated ' . Carbon::now('Asia/Manila')->format('Y-m-d H:i:s') . ' (Asia/Manila)'
        );

        $sheet->fromArray($headers, null, "A{$headerRow}");

        if ($rows !== []) {
            foreach ($rows as $rowOffset => $row) {
                foreach ($row as $columnOffset => $value) {
                    $cell = Coordinate::stringFromColumnIndex($columnOffset + 1)
                        . ($firstDataRow + $rowOffset);

                    // Keep real zero values visible instead of treating them as blank.
                    $sheet->setCellValue($cell, $value ?? '');
                }
            }
        }

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['ARGB' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF1F4E78']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
            'font' => ['italic' => true, 'color' => ['ARGB' => 'FF5B6573']],
        ]);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF2F75B5']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['ARGB' => 'FFD9E2F3'],
                ],
            ],
        ]);

        if ($rows !== []) {
            $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastRow}")->applyFromArray([
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                'borders' => [
                    'bottom' => [
                        'borderStyle' => Border::BORDER_HAIR,
                        'color' => ['ARGB' => 'FFE6E6E6'],
                    ],
                ],
            ]);
        }

        foreach ($headers as $index => $header) {
            $columnIndex = $index + 1;
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($column)->setWidth($this->columnWidth($header));

            if (in_array($header, $currencyHeaders, true) && $rows !== []) {
                $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('"' . "\u{20B1}" . '"#,##0.00');
            }

            if (in_array($header, $integerHeaders, true) && $rows !== []) {
                $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0');
            }

            if (in_array($header, ['Description', 'Address'], true) && $rows !== []) {
                $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastRow}")
                    ->getAlignment()
                    ->setWrapText(true);
            }
        }

        if ($title === 'Summary') {
            $sheet->getColumnDimension('A')->setWidth(30);
            $sheet->getColumnDimension('B')->setWidth(34);
        }

        $sheet->freezePane("A{$firstDataRow}");
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");
        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);
        $sheet->setSelectedCell('A1');
    }

    private function columnWidth(string $header): int
    {
        return match ($header) {
            'Description', 'Address' => 42,
            'Name', 'Product', 'Supplier', 'Customer' => 24,
            'Email' => 30,
            'Action' => 30,
            'Role', 'Status', 'Category' => 22,
            'Contact', 'IP Address', 'Batch Number', 'PO Number' => 20,
            'Received Date', 'Expiry Date', 'Created At', 'Approved At',
            'Ordered At', 'Fulfilled At', 'Cancelled At', 'Email Verified At',
            'Last Seen At', 'Order Date' => 22,
            default => 15,
        };
    }

    private function manilaDate(mixed $value, bool $includeTime = true): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = Carbon::parse((string) $value, 'UTC')->setTimezone('Asia/Manila');

        return $includeTime
            ? $date->format('Y-m-d H:i:s')
            : $date->format('Y-m-d');
    }

    private function cleanRow(array $row): array
    {
        return array_map(function ($value) {
            if (!is_string($value)) {
                return $value;
            }

            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

            // Prevent spreadsheet-formula injection from user-controlled text.
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                return "'" . $value;
            }

            return $value;
        }, $row);
    }
}
