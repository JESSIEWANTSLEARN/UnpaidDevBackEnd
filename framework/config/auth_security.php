<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Idle session policy
    |--------------------------------------------------------------------------
    |
    | The current testing policy is one minute total inactivity with a
    | warning during the final 15 seconds.
    |
    */

    'idle_seconds' => max(
        30,
        (int) env('SESSION_IDLE_SECONDS', 60)
    ),

    'warning_seconds' => max(
        5,
        (int) env('SESSION_IDLE_WARNING_SECONDS', 15)
    ),

    // Role-specific inactivity overrides.
    // Staff and admin roles receive a 24-hour inactivity window; System_User receives 1 minute.
    'role_idle_seconds' => [
    'super_admin' => 86400,
    'Operations_Manager' => 86400,
    'Purchasing_Manager' => 86400,
    'Purchasing_Staff' => 86400,
    'Warehouse_Admin' => 86400,
    'Inventory_Controller' => 86400,
    'Sales_Manager' => 86400,
    'Sales_Staff' => 86400,
    'User_Admin' => 86400,

    'System_User' => 60,
],

    /*
    |--------------------------------------------------------------------------
    | Trusted device policy
    |--------------------------------------------------------------------------
    */

    'trusted_device' => [
        'days' => max(
            1,
            (int) env('TRUSTED_DEVICE_DAYS', 30)
        ),

        'cookie_name' => env(
            'TRUSTED_DEVICE_COOKIE',
            'wbo_trusted_device'
        ),

        // Leave null to automatically follow the current HTTPS request.
        'secure_cookie' => env(
            'TRUSTED_DEVICE_SECURE_COOKIE'
        ),

        'same_site' => env(
            'TRUSTED_DEVICE_SAME_SITE',
            'lax'
        ),
    ],
    /*
    |--------------------------------------------------------------------------
    | Login rate limiting
    |--------------------------------------------------------------------------
    */
    'login_rate_limit' => [
        'max_attempts' => max(
            1,
            (int) env('LOGIN_MAX_ATTEMPTS', 5)
        ),
        'lock_seconds' => max(
            60,
            (int) env('LOGIN_LOCK_SECONDS', 300)
        ),
    ],
    // WBO Phase 3 tracked-session housekeeping.
    // A browser that disappears without logging out eventually becomes stale.
    'tracked_session_stale_hours' => max(
        1,
        (int) env('TRACKED_SESSION_STALE_HOURS', 24)
    ),

    // Ended tracked-session rows older than this are removed.
    // Login/security events should remain in WBO_AuditLogs.
    'tracked_session_retention_days' => max(
        7,
        (int) env('TRACKED_SESSION_RETENTION_DAYS', 90)
    ),

    'tracked_session_page_size' => min(
        25,
        max(
            5,
            (int) env('TRACKED_SESSION_PAGE_SIZE', 10)
        )
    ),
];
