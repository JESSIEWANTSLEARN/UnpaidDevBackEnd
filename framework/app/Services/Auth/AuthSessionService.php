<?php

namespace App\Services\Auth;

use App\Models\WBOUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthSessionService
{
    public function __construct(
        private DeviceInfoService $deviceInfo
    ) {
    }

    public function idleSeconds(?string $role = null): int
    {
        $roleSeconds = $role
            ? config("auth_security.role_idle_seconds.{$role}")
            : null;

        if ($roleSeconds !== null) {
            return max(30, (int) $roleSeconds);
        }

        return max(
            30,
            (int) config(
                'auth_security.idle_seconds',
                60
            )
        );
    }

    public function warningSeconds(?string $role = null): int
    {
        return min(
            $this->idleSeconds($role) - 1,
            max(
                5,
                (int) config(
                    'auth_security.warning_seconds',
                    15
                )
            )
        );
    }

    public function start(
        Request $request,
        WBOUser $user,
        string $action = 'LOGIN',
        string $description = 'User successfully logged in'
    ): void {
        $oldTrackingId =
            $request->session()->get('auth_session_id');

        if ($oldTrackingId) {
            DB::table('WBO_UserSessions')
                ->where('session_id', $oldTrackingId)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'logged_out_at' => now(),
                ]);
        }

        $request->session()->regenerate();

        $request->session()->put([
            'logged_in' => true,
            'user_id' => (int) $user->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'last_activity' => now()->timestamp,
        ]);

        $sessionId = $request->session()->getId();

        $request->session()->put(
            'auth_session_id',
            $sessionId
        );

        $this->writeSessionRow(
            $request,
            $user,
            $sessionId
        );

        $this->audit(
            $request,
            (int) $user->user_id,
            $action,
            $description
        );
    }

    public function validate(
        Request $request
    ): string {
        if (
            $request->session()->get('logged_in') !== true ||
            !$request->session()->get('user_id')
        ) {
            return 'guest';
        }

        $userId =
            (int) $request->session()->get('user_id');

        $user = WBOUser::where(
            'user_id',
            $userId
        )->first();

        if (
            !$user ||
            $user->account_status !== 'active'
        ) {
            $this->invalidate(
                $request,
                'SESSION_ACCOUNT_INVALID',
                'Session ended because the account is unavailable.'
            );

            return 'account_invalid';
        }

        // Keep authorization information current.
        $request->session()->put([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        // Check revocation before initializing legacy activity timestamps.
        $trackingId =
            $request->session()->get('auth_session_id');

        if ($trackingId) {
            $row = DB::table('WBO_UserSessions')
                ->where('session_id', $trackingId)
                ->first();

            if (
                $row &&
                (!(bool) $row->is_active || (int) $row->user_id !== $userId)
            ) {
                $this->invalidate(
                    $request,
                    'SESSION_REVOKED',
                    'User session was revoked.'
                );

                return 'revoked';
            }
        }

        $lastActivity =
            (int) $request->session()->get(
                'last_activity',
                0
            );

        if ($lastActivity <= 0) {
            $request->session()->put(
                'last_activity',
                now()->timestamp
            );

            $this->ensureTracked(
                $request,
                $user
            );

            return 'ok';
        }

        if (
            now()->timestamp - $lastActivity >=
            $this->idleSeconds((string) $user->role)
        ) {
            $this->invalidate(
                $request,
                'SESSION_IDLE_TIMEOUT',
                'User was automatically logged out after inactivity.'
            );

            return 'idle_timeout';
        }

        $this->ensureTracked(
            $request,
            $user
        );

        return 'ok';
    }

    public function touch(Request $request): bool
    {
        if (
            $request->session()->get('logged_in') !== true ||
            !$request->session()->get('user_id')
        ) {
            return false;
        }

        $user = WBOUser::where(
            'user_id',
            (int) $request->session()->get('user_id')
        )->first();

        if (
            !$user ||
            $user->account_status !== 'active'
        ) {
            return false;
        }

        $request->session()->put(
            'last_activity',
            now()->timestamp
        );

        $this->ensureTracked(
            $request,
            $user
        );

        $trackingId =
            $request->session()->get('auth_session_id');

        DB::table('WBO_UserSessions')
            ->where('session_id', $trackingId)
            ->where('is_active', true)
            ->update([
                'last_activity_at' => now(),
                'ip_address' => $request->ip(),
            ]);

        return true;
    }

    public function invalidate(
        Request $request,
        string $action,
        string $description
    ): void {
        $userId =
            $request->session()->get('user_id');

        $trackingId =
            $request->session()->get('auth_session_id');

        if ($trackingId) {
            DB::table('WBO_UserSessions')
                ->where('session_id', $trackingId)
                ->where('user_id', (int) $userId)
                ->update([
                    'is_active' => false,
                    'logged_out_at' => now(),
                ]);
        }

        if ($userId) {
            DB::table('WBO_Users')
                ->where('user_id', $userId)
                ->update([
                    'last_seen_at' => null,
                ]);

            $this->audit(
                $request,
                (int) $userId,
                $action,
                $description
            );
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function ensureTracked(
        Request $request,
        WBOUser $user
    ): void {
        $sessionId =
            $request->session()->get('auth_session_id');

        if (!$sessionId) {
            $sessionId =
                $request->session()->getId();

            $request->session()->put(
                'auth_session_id',
                $sessionId
            );
        }

        $exists = DB::table('WBO_UserSessions')
            ->where('session_id', $sessionId)
            ->exists();

        if (!$exists) {
            $this->writeSessionRow(
                $request,
                $user,
                $sessionId
            );
        }
    }

    private function writeSessionRow(
        Request $request,
        WBOUser $user,
        string $sessionId
    ): void {
        $info =
            $this->deviceInfo->fromRequest($request);

        DB::table('WBO_UserSessions')
            ->updateOrInsert(
                [
                    'session_id' => $sessionId,
                ],
                [
                    'user_id' => (int) $user->user_id,
                    'device_name' =>
                        $info['device_name'],
                    'browser_name' =>
                        $info['browser_name'],
                    'operating_system' =>
                        $info['operating_system'],
                    'ip_address' =>
                        $info['ip_address'],
                    'user_agent' =>
                        $info['user_agent'],
                    'logged_in_at' => now(),
                    'last_activity_at' => now(),
                    'logged_out_at' => null,
                    'is_active' => true,
                ]
            );
    }

    private function audit(
        Request $request,
        int $userId,
        string $action,
        string $description
    ): void {
        try {
            DB::table('WBO_AuditLogs')->insert([
                'user_id' => $userId,
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
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
