<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceIdleSession;
use App\Models\WBOUser;
use App\Services\Auth\AuthSessionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Isolated checks: never run application migrations or connect to the configured MySQL database. */
class RolePermissionHandshakeTest extends TestCase
{
    private const ROLES = ['super_admin', 'System_User', 'Operations_Manager', 'Purchasing_Manager', 'Purchasing_Staff', 'Warehouse_Admin', 'Inventory_Controller', 'Sales_Manager', 'Sales_Staff', 'User_Admin'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'session.driver' => 'array', 'cache.default' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        DB::purge('sqlite');
        Schema::create('WBO_Users', function (Blueprint $t) {
            $t->id('user_id'); $t->string('name'); $t->string('email'); $t->string('role'); $t->string('account_status'); $t->timestamp('last_seen_at')->nullable();
        });
        Schema::create('WBO_UserSessions', function (Blueprint $t) {
            $t->string('session_id')->primary(); $t->integer('user_id'); $t->boolean('is_active');
            foreach (['device_name','browser_name','operating_system','ip_address','user_agent','logged_in_at','last_activity_at','logged_out_at'] as $column) $t->string($column)->nullable();
        });
        Schema::create('WBO_AuditLogs', function (Blueprint $t) {
            $t->id(); $t->integer('user_id');
            foreach (['action','description','ip_address','user_agent','created_at'] as $column) $t->string($column)->nullable();
        });
    }

    public static function writePermissions(): iterable
    {
        $routes = [
            '/api/role-dashboard/stock-in' => ['Warehouse_Admin','Inventory_Controller'],
            '/api/role-dashboard/adjust-stock' => ['Inventory_Controller'],
            '/api/role-dashboard/write-off' => ['Inventory_Controller'],
            '/api/role-dashboard/suppliers' => ['Purchasing_Manager','Purchasing_Staff'],
            '/api/role-dashboard/purchase-orders' => ['Purchasing_Manager','Purchasing_Staff'],
            '/api/user-admin/users' => ['User_Admin'],
            '/api/super-admin/users' => ['super_admin'],
            '/api/super-admin/products' => ['super_admin'],
            '/api/super-admin/categories' => ['super_admin'],
        ];
        foreach ($routes as $url => $allowed) {
            foreach (self::ROLES as $role) yield "$role $url" => [$url, $role, in_array($role, $allowed, true) ? 422 : 403];
            yield "guest $url" => [$url, null, 401];
        }
    }

    #[DataProvider('writePermissions')]
    public function test_write_endpoints_enforce_actual_role_even_with_preview(string $url, ?string $role, int $expected): void
    {
        // Role authorization is isolated here; session validity is tested separately below.
        $this->withoutMiddleware(EnforceIdleSession::class);
        if ($role) $this->withSession(['logged_in' => true, 'user_id' => 1, 'role' => $role]);
        // Empty input must stop at validation for permitted roles, before any business mutation.
        $this->postJson($url.'?preview=1', [])->assertStatus($expected);
    }

    public static function forbiddenResources(): iterable
    {
        $resources = [
            ['GET', '/api/super-admin/dashboard-data', ['super_admin']],
            ['GET', '/api/user/me', ['System_User']],
            ['GET', '/api/user/reviews', ['System_User']],
            ['GET', '/api/user-admin/dashboard', ['User_Admin']],
            ['PUT', '/api/user/reviews/999', ['System_User']],
            ['PUT', '/api/user/profile', ['System_User']],
            ['DELETE', '/api/user/profile-photo', ['System_User']],
            ['PUT', '/api/super-admin/users/999', ['super_admin']],
            ['DELETE', '/api/super-admin/users/999', ['super_admin']],
            ['DELETE', '/api/super-admin/product-reviews/999', ['super_admin']],
            ['DELETE', '/api/user-admin/users/999/sessions', ['User_Admin']],
            ['PUT', '/api/sales-role/orders/999/status', ['Sales_Manager', 'Sales_Staff']],
            ['POST', '/api/role-dashboard/purchase-orders/999/receive', ['Warehouse_Admin']],
        ];
        foreach ($resources as [$method, $url, $allowed]) {
            foreach (self::ROLES as $role) {
                if (!in_array($role, $allowed, true)) yield "$role $method $url" => [$method, $url, $role];
            }
        }
    }

    #[DataProvider('forbiddenResources')]
    public function test_cross_role_read_update_delete_and_receive_are_denied(string $method, string $url, string $role): void
    {
        $this->withoutMiddleware(EnforceIdleSession::class);
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>$role]);
        $this->json($method, $url)->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_session_status_handshake_with_real_middleware(string $role): void
    {
        $this->actor($role);
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>$role, 'last_activity'=>now()->timestamp]);
        $this->getJson('/api/session/status')->assertOk()->assertJson(['authenticated'=>true, 'role'=>$role]);
    }

    public function test_customer_cannot_edit_another_customers_review(): void
    {
        $this->withoutMiddleware(EnforceIdleSession::class);
        Schema::create('WBO_ProductReviews', function (Blueprint $t) {
            $t->id('review_id'); $t->integer('user_id'); $t->integer('rating'); $t->string('comment');
        });
        DB::table('WBO_ProductReviews')->insert(['review_id'=>10, 'user_id'=>2, 'rating'=>5, 'comment'=>'Original']);
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'System_User']);
        $this->putJson('/api/user/reviews/10', ['rating'=>1, 'comment'=>'Changed'])->assertNotFound();
        $this->assertSame('Original', DB::table('WBO_ProductReviews')->where('review_id', 10)->value('comment'));
    }

    public function test_sales_staff_can_handle_routine_orders_but_not_manager_decisions(): void
    {
        $this->withoutMiddleware(EnforceIdleSession::class);
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'Sales_Staff']);
        foreach (['verify_payment', 'unfulfill', 'cancel'] as $action) {
            $this->putJson('/api/sales-role/orders/999/status', ['action'=>$action])->assertForbidden();
        }

        Schema::create('WBO_Orders', function (Blueprint $t) { $t->id('order_id'); });
        foreach (['process', 'fulfill'] as $action) {
            $this->putJson('/api/sales-role/orders/999/status', ['action'=>$action])->assertNotFound();
        }
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'Sales_Manager']);
        foreach (['verify_payment', 'unfulfill', 'cancel'] as $action) {
            $this->putJson('/api/sales-role/orders/999/status', ['action'=>$action])->assertNotFound();
        }
    }

    public function test_sales_staff_cannot_issue_refunds_but_can_review_returns(): void
    {
        $this->withoutMiddleware(EnforceIdleSession::class);
        Schema::create('WBO_ReturnRequests', function (Blueprint $t) { $t->id('return_id'); });
        Schema::create('WBO_ReturnItems', function (Blueprint $t) { $t->id('return_item_id'); });
        $this->actor('Sales_Staff');
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'Sales_Staff']);
        $this->putJson('/api/returns/999', ['action'=>'refund'])->assertForbidden();
        $this->putJson('/api/returns/999', ['action'=>'approve'])->assertNotFound();
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'Sales_Manager']);
        $this->putJson('/api/returns/999', ['action'=>'refund'])->assertNotFound();
    }

    public function test_operations_manager_can_preview_products_but_cannot_import_them(): void
    {
        $this->withoutMiddleware(EnforceIdleSession::class);
        Schema::create('WBO_DataImports', function (Blueprint $t) { $t->id('import_id'); $t->string('import_type'); $t->integer('uploaded_by_user_id'); $t->timestamp('created_at')->nullable(); });
        Schema::create('WBO_DataImportErrors', function (Blueprint $t) { $t->id('import_error_id'); $t->integer('import_id'); });
        $this->actor('Operations_Manager');
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'Operations_Manager']);
        $this->getJson('/api/data-imports')->assertOk()->assertJsonPath('can_confirm', false)->assertJsonPath('allowed_types.0', 'PRODUCTS');
        $file = fn () => UploadedFile::fake()->createWithContent('products.csv', "sku,name,unit_cost,unit_price\nBAD,,,foo\n");
        $this->postJson('/api/data-imports/preview', ['import_type'=>'PRODUCTS', 'file'=>$file()])->assertOk()->assertJsonPath('preview.invalid_rows', 1);
        $this->postJson('/api/data-imports', ['import_type'=>'PRODUCTS', 'file'=>$file()])->assertForbidden();
        $this->assertSame(0, DB::table('WBO_DataImports')->count());
        $this->withSession(['logged_in'=>true, 'user_id'=>1, 'role'=>'super_admin']);
        $this->getJson('/api/data-imports')->assertOk()->assertJsonPath('can_confirm', true);
    }

    public static function roles(): iterable
    {
        foreach (self::ROLES as $role) yield $role => [$role];
    }

    private function actor(string $role): WBOUser
    {
        DB::table('WBO_Users')->insert(['user_id'=>1, 'name'=>'Test Account', 'email'=>'test@example.test', 'role'=>$role, 'account_status'=>'active']);
        return WBOUser::findOrFail(1);
    }

    private function sessionRequest(): Request
    {
        $request = Request::create('/api/session/status');
        $session = app('session')->driver(); $session->start();
        $request->setLaravelSession($session);
        return $request;
    }

    #[DataProvider('roles')]
    public function test_session_start_validation_and_logout_for_every_role(string $role): void
    {
        $user = $this->actor($role); $request = $this->sessionRequest();
        $service = app(AuthSessionService::class); $oldId = $request->session()->getId();
        $service->start($request, $user);
        $this->assertNotSame($oldId, $request->session()->getId());
        $this->assertSame($role, $request->session()->get('role'));
        $this->assertSame('ok', $service->validate($request));
        $tracking = $request->session()->get('auth_session_id');
        $service->invalidate($request, 'LOGOUT', 'Test logout');
        $this->assertFalse((bool) $request->session()->get('logged_in'));
        $this->assertFalse((bool) DB::table('WBO_UserSessions')->where('session_id', $tracking)->value('is_active'));
    }

    #[DataProvider('roles')]
    public function test_revoked_session_without_activity_timestamp_cannot_pass(string $role): void
    {
        $user = $this->actor($role); $request = $this->sessionRequest(); $service = app(AuthSessionService::class);
        $service->start($request, $user);
        DB::table('WBO_UserSessions')->where('session_id', $request->session()->get('auth_session_id'))->update(['is_active'=>false]);
        $request->session()->forget('last_activity');
        $this->assertSame('revoked', $service->validate($request));
        $this->assertFalse((bool) $request->session()->get('logged_in'));
    }

    public function test_role_changes_and_account_disabling_take_effect_on_next_validation(): void
    {
        $user = $this->actor('Sales_Staff'); $request = $this->sessionRequest(); $service = app(AuthSessionService::class);
        $service->start($request, $user);
        DB::table('WBO_Users')->where('user_id', 1)->update(['role'=>'System_User']);
        $this->assertSame('ok', $service->validate($request));
        $this->assertSame('System_User', $request->session()->get('role'));
        DB::table('WBO_Users')->where('user_id', 1)->update(['account_status'=>'disabled']);
        $this->assertSame('account_invalid', $service->validate($request));
    }

    public function test_tracking_row_must_belong_to_current_user(): void
    {
        $user = $this->actor('System_User'); $request = $this->sessionRequest(); $service = app(AuthSessionService::class);
        $service->start($request, $user);
        DB::table('WBO_UserSessions')->insert(['session_id'=>'other-account', 'user_id'=>2, 'is_active'=>true]);
        $request->session()->put('auth_session_id', 'other-account');
        $this->assertSame('revoked', $service->validate($request));
        $this->assertTrue((bool) DB::table('WBO_UserSessions')->where('session_id', 'other-account')->value('is_active'));
    }

    public function test_expired_session_is_rejected(): void
    {
        $user = $this->actor('System_User'); $request = $this->sessionRequest(); $service = app(AuthSessionService::class);
        $service->start($request, $user);
        $request->session()->put('last_activity', now()->timestamp - $service->idleSeconds('System_User') - 1);
        $this->assertSame('idle_timeout', $service->validate($request));
    }
}
