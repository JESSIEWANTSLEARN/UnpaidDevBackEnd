<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait HandlesSuperAdminSupport
{
    protected function authorizeSuperAdmin(): void
    {
        if (session('logged_in') !== true || !session('user_id')) {
            throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
        }
        if (session('role') !== 'super_admin') {
            throw new HttpResponseException(response()->json([
                'message' => 'You are not authorized to access the Super Admin portal.'
            ], 403));
        }
    }

    protected function systemSettings(): array
    {
        $defaultLogo = 'site/Logo.png';

        $defaults = [
            'table_ready' => false,
            'company_name' => 'Walang Brownout',
            'company_email' => '',
            'company_contact' => '',
            'company_address' => '',
            'company_logo' => $defaultLogo,
            'company_logo_path' => $defaultLogo,
            'company_logo_url' => Storage::url($defaultLogo),
        ];

        if (!Schema::hasTable('WBO_SystemSettings')) {
            return $defaults;
        }

        $values = DB::table('WBO_SystemSettings')
            ->pluck('setting_value', 'setting_key');

        $logoPath = $values->get('company_logo')
            ?: $values->get('company_logo_path')
            ?: $defaultLogo;

        return [
            'table_ready' => true,
            'company_name' => $values->get('company_name', 'Walang Brownout') ?: 'Walang Brownout',
            'company_email' => $values->get('company_email', '') ?: '',
            'company_contact' => $values->get('company_contact', '') ?: '',
            'company_address' => $values->get('company_address', '') ?: '',
            'company_logo' => $logoPath,
            'company_logo_path' => $logoPath,
            'company_logo_url' => Storage::url($logoPath),
        ];
    }

    protected function backupTables(): array
    {
        return [
            'WBO_Users', 'WBO_DataImports', 'WBO_DataImportErrors',
            'WBO_Suppliers', 'WBO_Products', 'WBO_ProductImages', 'WBO_Batches',
            'WBO_Orders', 'WBO_OrderDetails', 'WBO_ReturnRequests', 'WBO_ReturnItems',
            'WBO_Transactions', 'WBO_PurchaseOrders',
            'WBO_Notifications', 'WBO_AuditLogs', 'WBO_SystemSettings',
            'WBO_Conversations', 'WBO_ConversationMessages', 'WBO_ConversationTransfers',
        ];
    }

    protected function writeBackup(string $reason): string
    {
        Storage::disk('local')->makeDirectory('backups');
        $tables = [];
        foreach ($this->backupTables() as $table) {
            if (!Schema::hasTable($table)) continue;
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->values()->all();
        }
        $payload = [
            'schema_version' => 1,
            'database' => config('database.connections.mysql.database'),
            'created_at' => now()->toIso8601String(),
            'created_by_user_id' => session('user_id'),
            'reason' => $reason,
            'tables' => $tables,
        ];
        $filename = sprintf('wbo-backup-%s-%s.json', now()->format('Ymd-His'), Str::lower(Str::random(6)));
        Storage::disk('local')->put('backups/' . $filename, json_encode(
            $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        return $filename;
    }

    protected function backupList(): array
    {
        if (!Storage::disk('local')->exists('backups')) return [];
        return collect(Storage::disk('local')->files('backups'))
            ->filter(fn ($path) => str_ends_with($path, '.json'))
            ->map(function ($path) {
                $size = Storage::disk('local')->size($path);
                return [
                    'filename' => basename($path),
                    'size' => $size,
                    'size_label' => $this->formatBytes($size),
                    'modified_at' => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($path))->toIso8601String(),
                ];
            })->sortByDesc('modified_at')->values()->all();
    }

    protected function backupPath(string $filename): ?string
    {
        $safeName = basename($filename);
        if ($safeName !== $filename || !preg_match('/^wbo-backup-[A-Za-z0-9._-]+\.json$/', $safeName)) return null;
        return 'backups/' . $safeName;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    protected function audit(Request $request, string $action, ?string $description = null): void
    {
        DB::table('WBO_AuditLogs')->insert([
            'user_id' => session('user_id'),
            'action' => Str::limit($action, 100, ''),
            'description' => $description ? Str::limit($description, 255, '') : null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
