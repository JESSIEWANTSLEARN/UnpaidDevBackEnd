<?php

namespace App\Http\Controllers\UserAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/*
 * WBO_USER_ADMIN_CONTROLLER_V1
 *
 * User Admin manages non-Super-Admin accounts only.
 *
 * Security rules:
 * - Super Admin is never returned by this API and cannot be modified.
 * - User Admin cannot promote anyone to super_admin.
 * - User Admin cannot change their own role or account status.
 * - Disabling/pending an account revokes its sessions and trusted devices.
 * - Super Admin role preview has read-only GET access only.
 */
/** User Admin customer-account and customer-session administration endpoints. */
class UserAdminController extends Controller
{
    private const MANAGEABLE_ROLES = [
        'Operations_Manager',
        'Purchasing_Manager',
        'Warehouse_Admin',
        'Sales_Manager',
        'Purchasing_Staff',
        'Inventory_Controller',
        'Sales_Staff',
        'User_Admin',
        'System_User',
    ];

    private const ACCOUNT_STATUSES = [
        'pending_verification',
        'active',
        'disabled',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $isPreview = $this->authorizeRead($request);
        $validated = $request->validate(['user_id' => ['sometimes', 'integer', 'min:1']]);
        $targetId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;

        $users = DB::table('WBO_Users as u')
            ->where('u.role', '<>', 'super_admin')
            ->when($targetId !== null, fn ($query) => $query->where('u.user_id', $targetId))
            ->select(
                'u.user_id',
                'u.name',
                'u.email',
                'u.contact_number',
                'u.role',
                'u.account_status',
                'u.email_verified_at',
                'u.created_at',
                'u.last_seen_at'
            )
            ->orderBy('u.name')
            ->get()
            ->map(function ($user) {
                $user->user_id = (int) $user->user_id;
                $user->active_sessions = 0;
                $user->last_session_activity = null;

                return $user;
            });

        if (Schema::hasTable('WBO_UserSessions')) {
            $sessionSummary = DB::table('WBO_UserSessions')
                ->when($targetId !== null, fn ($query) => $query->where('user_id', $targetId))
                ->select(
                    'user_id',
                    DB::raw(
                        'SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_sessions'
                    ),
                    DB::raw(
                        'MAX(last_activity_at) AS last_session_activity'
                    )
                )
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $users = $users->map(
                function ($user) use ($sessionSummary) {
                    $summary =
                        $sessionSummary->get(
                            $user->user_id
                        );

                    if ($summary) {
                        $user->active_sessions =
                            (int) $summary->active_sessions;
                        $user->last_session_activity =
                            $summary->last_session_activity;
                    }

                    return $user;
                }
            );
        }

        $roleCounts = $users
            ->groupBy('role')
            ->map(fn($rows) => $rows->count());

        $statusCounts = $users
            ->groupBy('account_status')
            ->map(fn($rows) => $rows->count());

        $totalUsers = $users->count();
        $activeSessions = (int) $users->sum('active_sessions');
        $staffAccounts = $users->where('role', '<>', 'System_User')->count();
        if ($targetId !== null) {
            // Global counters remain global without retrieving every account/session row.
            $roleCounts = DB::table('WBO_Users')->where('role', '<>', 'super_admin')
                ->select('role', DB::raw('COUNT(*) AS total'))->groupBy('role')->pluck('total', 'role')->map(fn ($count) => (int) $count);
            $statusCounts = DB::table('WBO_Users')->where('role', '<>', 'super_admin')
                ->select('account_status', DB::raw('COUNT(*) AS total'))->groupBy('account_status')->pluck('total', 'account_status')->map(fn ($count) => (int) $count);
            $totalUsers = (int) $roleCounts->sum();
            $staffAccounts = $totalUsers - (int) $roleCounts->get('System_User', 0);
            $activeSessions = Schema::hasTable('WBO_UserSessions')
                ? DB::table('WBO_UserSessions as s')->join('WBO_Users as u', 'u.user_id', '=', 's.user_id')
                    ->where('u.role', '<>', 'super_admin')->where('s.is_active', true)->count()
                : 0;
        }

        $recentAccess = collect();

        if (Schema::hasTable('WBO_AuditLogs')) {
            $recentAccess = DB::table('WBO_AuditLogs as a')
                ->leftJoin(
                    'WBO_Users as u',
                    'u.user_id',
                    '=',
                    'a.user_id'
                )
                ->where(function ($query) {
                    $query
                        ->where(
                            'a.action',
                            'like',
                            'USER_%'
                        )
                        ->orWhere(
                            'a.action',
                            'like',
                            'SESSION_%'
                        )
                        ->orWhere(
                            'a.action',
                            'like',
                            'LOGIN%'
                        );
                })
                ->where(function ($query) {
                    $query
                        ->whereNull('u.role')
                        ->orWhere(
                            'u.role',
                            '<>',
                            'super_admin'
                        );
                })
                ->select(
                    'a.log_id as audit_id',
                    'a.user_id',
                    'u.name as user_name',
                    'u.role as user_role',
                    'a.action',
                    'a.description',
                    'a.ip_address',
                    'a.created_at'
                )
                ->orderByDesc('a.created_at')
                ->limit(60)
                ->get();
        }

        return response()->json([
            'preview' => $isPreview,
            'partial_users' => $targetId !== null,
            'current_user_id' =>
                (int) session('user_id'),
            'manageable_roles' =>
                self::MANAGEABLE_ROLES,
            'account_statuses' =>
                self::ACCOUNT_STATUSES,
            'metrics' => [
                'total_users' => $totalUsers,
                'active_users' =>
                    $statusCounts->get(
                        'active',
                        0
                    ),
                'pending_users' =>
                    $statusCounts->get(
                        'pending_verification',
                        0
                    ),
                'disabled_users' =>
                    $statusCounts->get(
                        'disabled',
                        0
                    ),
                'active_sessions' =>
                    $activeSessions,
                'staff_accounts' =>
                    $staffAccounts,
                'customer_accounts' =>
                    $roleCounts->get(
                        'System_User',
                        0
                    ),
            ],
            'role_counts' => $roleCounts,
            'status_counts' => $statusCounts,
            'users' => $users,
            'recent_access' => $recentAccess,
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->authorizeWrite();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
            ],
            'email' => [
                'required',
                'email',
                'max:100',
                Rule::unique(
                    'WBO_Users',
                    'email'
                ),
            ],
            'contact_number' => [
                'nullable',
                'string',
                'max:20',
            ],
            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],
            'role' => [
                'required',
                Rule::in(
                    self::MANAGEABLE_ROLES
                ),
            ],
            'account_status' => [
                'required',
                Rule::in(
                    self::ACCOUNT_STATUSES
                ),
            ],
        ]);

        $userId = DB::table('WBO_Users')
            ->insertGetId([
                'name' =>
                    trim($validated['name']),
                'email' =>
                    strtolower(
                        trim(
                            $validated['email']
                        )
                    ),
                'contact_number' =>
                    $validated[
                        'contact_number'
                    ] ?: null,
                'password_hash' =>
                    Hash::make(
                        $validated['password']
                    ),
                'role' =>
                    $validated['role'],
                'account_status' =>
                    $validated[
                        'account_status'
                    ],
                'email_verified_at' =>
                    $validated[
                        'account_status'
                    ] === 'active'
                        ? now()
                        : null,
                'created_at' => now(),
            ]);

        $this->audit(
            $request,
            'USER_ADMIN_CREATED_USER',
            "User Admin created account #{$userId} with role {$validated['role']}."
        );

        return response()->json([
            'message' =>
                'User account created successfully.',
            'user_id' => $userId,
        ], 201);
    }

    public function updateUser(
        Request $request,
        int $userId
    ): JsonResponse {
        $this->authorizeWrite();

        $user = $this->manageableUser($userId);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
            ],
            'email' => [
                'required',
                'email',
                'max:100',
                Rule::unique(
                    'WBO_Users',
                    'email'
                )->ignore(
                    $userId,
                    'user_id'
                ),
            ],
            'contact_number' => [
                'nullable',
                'string',
                'max:20',
            ],
            'role' => [
                'required',
                Rule::in(
                    self::MANAGEABLE_ROLES
                ),
            ],
            'account_status' => [
                'required',
                Rule::in(
                    self::ACCOUNT_STATUSES
                ),
            ],
        ]);

        $isSelf =
            $userId ===
            (int) session('user_id');

        $nextRole = $isSelf
            ? $user->role
            : $validated['role'];

        $nextStatus = $isSelf
            ? $user->account_status
            : $validated[
                'account_status'
            ];

        $emailVerifiedAt =
            $user->email_verified_at;

        if (
            $nextStatus === 'active' &&
            !$emailVerifiedAt
        ) {
            $emailVerifiedAt = now();
        }

        DB::table('WBO_Users')
            ->where('user_id', $userId)
            ->update([
                'name' =>
                    trim($validated['name']),
                'email' =>
                    strtolower(
                        trim(
                            $validated['email']
                        )
                    ),
                'contact_number' =>
                    $validated[
                        'contact_number'
                    ] ?: null,
                'role' => $nextRole,
                'account_status' =>
                    $nextStatus,
                'email_verified_at' =>
                    $emailVerifiedAt,
            ]);

        if (
            !$isSelf &&
            $nextStatus !== 'active'
        ) {
            $this->revokeSessionsForUser(
                $userId
            );

            $this->revokeTrustedDevicesForUser(
                $userId
            );

            DB::table('WBO_Users')
                ->where(
                    'user_id',
                    $userId
                )
                ->update([
                    'last_seen_at' => null,
                ]);
        }

        if ($isSelf) {
            session([
                'name' =>
                    trim($validated['name']),
                'email' =>
                    strtolower(
                        trim(
                            $validated['email']
                        )
                    ),
            ]);
        }

        $this->audit(
            $request,
            'USER_ADMIN_UPDATED_USER',
            sprintf(
                'User Admin updated account #%d. Role: %s; status: %s%s.',
                $userId,
                $nextRole,
                $nextStatus,
                $isSelf
                    ? ' (own role/status protected)'
                    : ''
            )
        );

        return response()->json([
            'message' =>
                $isSelf
                    ? 'Your account details were updated. Your role and status were kept unchanged.'
                    : 'User account updated successfully.',
        ]);
    }

    public function sessions(
        Request $request,
        int $userId
    ): JsonResponse {
        $isPreview =
            $this->authorizeRead($request);

        $user =
            $this->manageableUser($userId);

        if (
            !Schema::hasTable(
                'WBO_UserSessions'
            )
        ) {
            return response()->json([
                'preview' => $isPreview,
                'user' => $user,
                'sessions' => [],
            ]);
        }

        $currentTrackingId =
            (string) session(
                'auth_session_id',
                ''
            );

        $sessions =
            DB::table('WBO_UserSessions')
                ->where(
                    'user_id',
                    $userId
                )
                ->orderByDesc(
                    'is_active'
                )
                ->orderByDesc(
                    'last_activity_at'
                )
                ->orderByDesc(
                    'logged_in_at'
                )
                ->limit(30)
                ->get()
                ->map(
                    function (
                        $row
                    ) use (
                        $currentTrackingId
                    ) {
                        return [
                            'session_id' =>
                                (string)
                                $row
                                    ->session_id,
                            'device_name' =>
                                $row
                                    ->device_name
                                    ?: 'Unknown Device',
                            'browser_name' =>
                                $row
                                    ->browser_name
                                    ?: 'Unknown Browser',
                            'operating_system' =>
                                $row
                                    ->operating_system
                                    ?: 'Unknown OS',
                            'ip_address' =>
                                $row
                                    ->ip_address
                                    ?: 'Unknown',
                            'logged_in_at' =>
                                $row
                                    ->logged_in_at,
                            'last_activity_at' =>
                                $row
                                    ->last_activity_at,
                            'logged_out_at' =>
                                $row
                                    ->logged_out_at,
                            'is_active' =>
                                (bool)
                                $row
                                    ->is_active,
                            'is_current_session' =>
                                $currentTrackingId !==
                                    '' &&
                                hash_equals(
                                    $currentTrackingId,
                                    (string)
                                    $row
                                        ->session_id
                                ),
                        ];
                    }
                )
                ->values();

        return response()->json([
            'preview' => $isPreview,
            'user' => $user,
            'sessions' => $sessions,
            'timezone' =>
                'Asia/Manila',
        ]);
    }

    public function revokeSession(
        Request $request,
        int $userId,
        string $sessionId
    ): JsonResponse {
        $this->authorizeWrite();

        $user =
            $this->manageableUser($userId);

        if (
            !Schema::hasTable(
                'WBO_UserSessions'
            )
        ) {
            abort(
                404,
                'Session tracking is not installed.'
            );
        }

        $tracked =
            DB::table('WBO_UserSessions')
                ->where(
                    'user_id',
                    $userId
                )
                ->where(
                    'session_id',
                    $sessionId
                )
                ->first();

        if (!$tracked) {
            abort(
                404,
                'Session not found.'
            );
        }

        if (
            $userId ===
                (int) session('user_id') &&
            (string) session(
                'auth_session_id',
                ''
            ) ===
                (string)
                $sessionId
        ) {
            return response()->json([
                'message' =>
                    'Your current User Admin session cannot be revoked from this page.',
            ], 409);
        }

        DB::table('WBO_UserSessions')
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'session_id',
                $sessionId
            )
            ->update([
                'is_active' => false,
                'logged_out_at' => now(),
            ]);

        $this->deleteLaravelSessions([
            $sessionId,
        ]);

        $this->clearPresenceWhenNoActiveSession(
            $userId
        );

        $this->audit(
            $request,
            'USER_ADMIN_REVOKED_SESSION',
            "User Admin revoked a session for account #{$userId} ({$user->email})."
        );

        return response()->json([
            'message' =>
                'Session revoked successfully.',
        ]);
    }

    public function revokeAllSessions(
        Request $request,
        int $userId
    ): JsonResponse {
        $this->authorizeWrite();

        $user =
            $this->manageableUser($userId);

        $except =
            $userId ===
            (int) session('user_id')
                ? (string) session(
                    'auth_session_id',
                    ''
                )
                : null;

        $revoked =
            $this->revokeSessionsForUser(
                $userId,
                $except ?: null
            );

        if ($userId !==
            (int) session('user_id')) {
            $this->revokeTrustedDevicesForUser(
                $userId
            );
        }

        $this->clearPresenceWhenNoActiveSession(
            $userId
        );

        $this->audit(
            $request,
            'USER_ADMIN_REVOKED_ALL_SESSIONS',
            "User Admin revoked {$revoked} session(s) for account #{$userId} ({$user->email})."
        );

        return response()->json([
            'message' =>
                $userId ===
                (int) session('user_id')
                    ? 'All other active sessions were revoked.'
                    : 'All active sessions were revoked.',
            'revoked_sessions' =>
                $revoked,
        ]);
    }

    private function authorizeRead(
        Request $request
    ): bool {
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

        if ($role === 'User_Admin') {
            return false;
        }

        if (
            $role === 'super_admin' &&
            $request->boolean('preview')
        ) {
            return true;
        }

        abort(
            403,
            'User administration access denied.'
        );
    }

    private function authorizeWrite(): void
    {
        if (
            session('logged_in') !== true
        ) {
            abort(
                401,
                'Authentication required.'
            );
        }

        if (
            session('role') !==
            'User_Admin'
        ) {
            abort(
                403,
                'This User Admin action is not allowed.'
            );
        }
    }

    private function manageableUser(
        int $userId
    ): object {
        $user =
            DB::table('WBO_Users')
                ->select(
                    'user_id',
                    'name',
                    'email',
                    'contact_number',
                    'role',
                    'account_status',
                    'email_verified_at',
                    'created_at',
                    'last_seen_at'
                )
                ->where(
                    'user_id',
                    $userId
                )
                ->first();

        if (!$user) {
            abort(
                404,
                'User not found.'
            );
        }

        if (
            $user->role ===
            'super_admin'
        ) {
            abort(
                403,
                'Super Admin accounts cannot be managed by User Admin.'
            );
        }

        return $user;
    }

    private function revokeSessionsForUser(
        int $userId,
        ?string $exceptSessionId = null
    ): int {
        if (
            !Schema::hasTable(
                'WBO_UserSessions'
            )
        ) {
            return 0;
        }

        $query =
            DB::table('WBO_UserSessions')
                ->where(
                    'user_id',
                    $userId
                )
                ->where(
                    'is_active',
                    true
                );

        if ($exceptSessionId) {
            $query->where(
                'session_id',
                '<>',
                $exceptSessionId
            );
        }

        $sessionIds =
            (clone $query)
                ->pluck('session_id')
                ->map(
                    fn($value) =>
                        (string) $value
                )
                ->values()
                ->all();

        if ($sessionIds === []) {
            return 0;
        }

        $updated =
            $query->update([
                'is_active' => false,
                'logged_out_at' => now(),
            ]);

        $this->deleteLaravelSessions(
            $sessionIds
        );

        return (int) $updated;
    }

    private function deleteLaravelSessions(
        array $sessionIds
    ): void {
        if (
            $sessionIds === [] ||
            !Schema::hasTable(
                'sessions'
            ) ||
            !Schema::hasColumn(
                'sessions',
                'id'
            )
        ) {
            return;
        }

        DB::table('sessions')
            ->whereIn(
                'id',
                $sessionIds
            )
            ->delete();
    }

    private function revokeTrustedDevicesForUser(
        int $userId
    ): int {
        if (
            !Schema::hasTable(
                'WBO_TrustedDevices'
            )
        ) {
            return 0;
        }

        return DB::table(
            'WBO_TrustedDevices'
        )
            ->where(
                'user_id',
                $userId
            )
            ->whereNull(
                'revoked_at'
            )
            ->update([
                'revoked_at' => now(),
            ]);
    }

    private function clearPresenceWhenNoActiveSession(
        int $userId
    ): void {
        if (
            !Schema::hasTable(
                'WBO_UserSessions'
            )
        ) {
            return;
        }

        $hasActiveSession =
            DB::table(
                'WBO_UserSessions'
            )
                ->where(
                    'user_id',
                    $userId
                )
                ->where(
                    'is_active',
                    true
                )
                ->exists();

        if (!$hasActiveSession) {
            DB::table('WBO_Users')
                ->where(
                    'user_id',
                    $userId
                )
                ->update([
                    'last_seen_at' => null,
                ]);
        }
    }

    private function audit(
        Request $request,
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

        try {
            DB::table(
                'WBO_AuditLogs'
            )->insert([
                'user_id' =>
                    (int)
                    session('user_id'),
                'action' => $action,
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
}