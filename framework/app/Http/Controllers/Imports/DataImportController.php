<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\WBOUser;
use App\Services\Imports\DataImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DataImportController extends Controller
{
    private const ROLE_IMPORTS = [
        'super_admin' => [
            'PRODUCTS',
            'SUPPLIERS',
            'INVENTORY',
        ],
        'Operations_Manager' => [
            'PRODUCTS',
        ],
        'Purchasing_Manager' => [
            'SUPPLIERS',
        ],
        'Purchasing_Staff' => [
            'SUPPLIERS',
        ],
        'Warehouse_Admin' => [
            'INVENTORY',
        ],
        'Inventory_Controller' => [
            'INVENTORY',
        ],
    ];

    public function index(
        Request $request
    ): JsonResponse {
        $access = $this->authorizeRead($request);
        $this->assertTables();

        $query = DB::table('WBO_DataImports as i')
            ->leftJoin(
                'WBO_Users as u',
                'u.user_id',
                '=',
                'i.uploaded_by_user_id'
            )
            ->select(
                'i.*',
                'u.name as uploaded_by_name'
            );

        if (
            $access['role'] !== 'super_admin'
        ) {
            $query->whereIn(
                'i.import_type',
                $access['allowed_types']
            );
        }

        $imports = $query
            ->orderByDesc('i.created_at')
            ->orderByDesc('i.import_id')
            ->limit(30)
            ->get();

        $ids = $imports
            ->pluck('import_id')
            ->all();

        $errors = $ids
            ? DB::table('WBO_DataImportErrors')
                ->whereIn('import_id', $ids)
                ->orderByDesc('import_error_id')
                ->get()
                ->groupBy('import_id')
            : collect();

        $payload = $imports
            ->map(function ($item) use ($errors) {
                $itemErrors = collect(
                    $errors->get(
                        $item->import_id,
                        []
                    )
                );

                return [
                    'import_id' =>
                        (int) $item->import_id,
                    'import_type' =>
                        $item->import_type,
                    'file_type' =>
                        $item->file_type,
                    'original_filename' =>
                        $item->original_filename,
                    'status' =>
                        $item->status,
                    'total_rows' =>
                        (int) $item->total_rows,
                    'successful_rows' =>
                        (int) $item->successful_rows,
                    'failed_rows' =>
                        (int) $item->failed_rows,
                    'started_at' =>
                        $item->started_at,
                    'completed_at' =>
                        $item->completed_at,
                    'created_at' =>
                        $item->created_at,
                    'uploaded_by_name' =>
                        $item->uploaded_by_name,
                    'error_count' =>
                        $itemErrors->count(),
                    'errors' =>
                        $itemErrors
                            ->take(5)
                            ->map(
                                fn ($error) => [
                                    'import_error_id' =>
                                        (int)
                                        $error->import_error_id,
                                    'row_number' =>
                                        $error->row_number !== null
                                            ? (int)
                                            $error->row_number
                                            : null,
                                    'field_name' =>
                                        $error->field_name,
                                    'error_message' =>
                                        $error->error_message,
                                ]
                            )
                            ->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'preview' => $access['preview'],
            'allowed_types' =>
                $access['allowed_types'],
            'imports' => $payload,
        ]);
    }

    public function preview(
        Request $request,
        DataImportService $imports
    ): JsonResponse {
        $this->assertTables();

        $validated = $this->validateUpload(
            $request
        );

        $this->authorizeWrite(
            $validated['import_type']
        );

        $preview = $imports->inspect(
            $validated['file']
                ->getRealPath(),
            $validated['import_type']
        );

        return response()->json([
            'success' => true,
            'preview' => $preview,
        ]);
    }

    public function store(
        Request $request,
        DataImportService $imports
    ): JsonResponse {
        $this->assertTables();

        $validated = $this->validateUpload(
            $request
        );

        $actor = $this->authorizeWrite(
            $validated['import_type']
        );

        $file = $validated['file'];
        $type = $validated['import_type'];
        $fileType = $this->fileType(
            $file->getClientOriginalExtension()
        );

        $safeBase = Str::slug(
            pathinfo(
                $file->getClientOriginalName(),
                PATHINFO_FILENAME
            )
        ) ?: 'import';

        $storedPath = $file->storeAs(
            'imports',
            now()->format('Ymd-His')
                . '-'
                . Str::lower(Str::random(6))
                . '-'
                . $safeBase
                . '.'
                . Str::lower(
                    $file->getClientOriginalExtension()
                ),
            'local'
        );

        $importId = DB::table(
            'WBO_DataImports'
        )->insertGetId([
            'uploaded_by_user_id' =>
                (int) $actor->user_id,
            'import_type' => $type,
            'file_type' => $fileType,
            'original_filename' =>
                Str::limit(
                    $file->getClientOriginalName(),
                    255,
                    ''
                ),
            'stored_file_path' => $storedPath,
            'status' => 'PROCESSING',
            'total_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
        ]);

        try {
            $report = $imports->run(
                Storage::disk('local')
                    ->path($storedPath),
                $type,
                (int) $actor->user_id,
                (int) $importId
            );

            foreach ($report['errors'] as $error) {
                DB::table(
                    'WBO_DataImportErrors'
                )->insert([
                    'import_id' => $importId,
                    'row_number' =>
                        $error['row_number'],
                    'sheet_name' =>
                        $error['sheet_name'] ?? null,
                    'field_name' =>
                        $error['field_name'] ?? null,
                    'raw_value' =>
                        $error['raw_value'] ?? null,
                    'raw_row_data' =>
                        isset($error['raw_row'])
                            ? json_encode(
                                $error['raw_row'],
                                JSON_UNESCAPED_UNICODE
                            )
                            : null,
                    'error_message' =>
                        Str::limit(
                            $error['message'],
                            500,
                            ''
                        ),
                    'created_at' => now(),
                ]);
            }

            $status =
                $report['successful_rows'] > 0 &&
                $report['failed_rows'] > 0
                    ? 'PARTIAL'
                    : (
                        $report['successful_rows'] > 0
                            ? 'COMPLETED'
                            : 'FAILED'
                    );

            DB::table('WBO_DataImports')
                ->where(
                    'import_id',
                    $importId
                )
                ->update([
                    'status' => $status,
                    'total_rows' =>
                        $report['total_rows'],
                    'successful_rows' =>
                        $report['successful_rows'],
                    'failed_rows' =>
                        $report['failed_rows'],
                    'completed_at' => now(),
                ]);

            $this->audit(
                $request,
                (int) $actor->user_id,
                'DATA_IMPORT_COMPLETED',
                "Import #{$importId} {$type}: "
                    . "{$report['successful_rows']} successful, "
                    . "{$report['failed_rows']} failed."
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Data import finished.',
                'import' => [
                    'import_id' =>
                        (int) $importId,
                    'status' => $status,
                    'total_rows' =>
                        $report['total_rows'],
                    'successful_rows' =>
                        $report['successful_rows'],
                    'failed_rows' =>
                        $report['failed_rows'],
                ],
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            DB::table('WBO_DataImports')
                ->where(
                    'import_id',
                    $importId
                )
                ->update([
                    'status' => 'FAILED',
                    'completed_at' => now(),
                ]);

            DB::table(
                'WBO_DataImportErrors'
            )->insert([
                'import_id' => $importId,
                'row_number' => null,
                'sheet_name' => null,
                'field_name' => null,
                'raw_value' => null,
                'raw_row_data' => null,
                'error_message' =>
                    Str::limit(
                        $exception->getMessage()
                            ?: 'Import processing failed.',
                        500,
                        ''
                    ),
                'created_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function authorizeRead(
        Request $request
    ): array {
        if (
            session('logged_in') !== true ||
            !session('user_id')
        ) {
            abort(401, 'Authentication required.');
        }

        $role = (string) session('role');
        $allowed =
            self::ROLE_IMPORTS[$role] ?? [];

        if (!$allowed) {
            abort(
                403,
                'This role has no data-import permission.'
            );
        }

        if (
            $role === 'super_admin' &&
            $request->boolean('preview')
        ) {
            $previewRole = (string)
                $request->query(
                    'preview_role',
                    'super_admin'
                );

            $previewAllowed =
                self::ROLE_IMPORTS[$previewRole]
                ?? self::ROLE_IMPORTS['super_admin'];

            return [
                'role' => $previewRole,
                'allowed_types' =>
                    $previewAllowed,
                'preview' => true,
            ];
        }

        $this->activeUser();

        return [
            'role' => $role,
            'allowed_types' => $allowed,
            'preview' => false,
        ];
    }

    private function authorizeWrite(
        string $type
    ): WBOUser {
        if (
            session('logged_in') !== true ||
            !session('user_id')
        ) {
            abort(401, 'Authentication required.');
        }

        $role = (string) session('role');
        $allowed =
            self::ROLE_IMPORTS[$role] ?? [];

        if (
            !in_array(
                $type,
                $allowed,
                true
            )
        ) {
            abort(
                403,
                "Your role cannot import {$type}."
            );
        }

        return $this->activeUser();
    }

    private function activeUser(): WBOUser
    {
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

    private function validateUpload(
        Request $request
    ): array {
        return $request->validate([
            'import_type' => [
                'required',
                'string',
                Rule::in([
                    'PRODUCTS',
                    'SUPPLIERS',
                    'INVENTORY',
                ]),
            ],
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:csv,txt,xls,xlsx',
            ],
        ]);
    }

    private function fileType(
        string $extension
    ): string {
        return match (
            Str::lower($extension)
        ) {
            'xls' => 'XLS',
            'xlsx' => 'XLSX',
            default => 'CSV',
        };
    }

    private function assertTables(): void
    {
        if (
            !Schema::hasTable('WBO_DataImports') ||
            !Schema::hasTable('WBO_DataImportErrors')
        ) {
            abort(
                503,
                'Data import tables are not installed.'
            );
        }
    }

    private function audit(
        Request $request,
        int $userId,
        string $action,
        string $description
    ): void {
        if (!Schema::hasTable('WBO_AuditLogs')) {
            return;
        }

        DB::table('WBO_AuditLogs')->insert([
            'user_id' => $userId,
            'action' =>
                Str::limit($action, 100, ''),
            'description' =>
                Str::limit(
                    $description,
                    500,
                    ''
                ),
            'ip_address' => $request->ip(),
            'user_agent' =>
                Str::limit(
                    (string) $request->userAgent(),
                    500,
                    ''
                ),
            'created_at' => now(),
        ]);
    }
}
