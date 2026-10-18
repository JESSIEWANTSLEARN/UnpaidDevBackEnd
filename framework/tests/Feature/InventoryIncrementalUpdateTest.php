<?php
namespace Tests\Feature;

use App\Http\Middleware\EnforceIdleSession;
use App\Services\Inventory\InventoryUpdateService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Disposable SQLite fixtures: never runs migrations or uses the configured MySQL database. */
class InventoryIncrementalUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','cache.default'=>'array','app.key'=>'base64:'.base64_encode(str_repeat('t',32))]);
        DB::purge('sqlite'); Carbon::setTestNow('2026-10-18 12:00:00');
        $this->withoutMiddleware(EnforceIdleSession::class); Storage::fake('local');
        Schema::create('WBO_Users',function(Blueprint $t){$t->id('user_id');foreach(['name','email','role','account_status']as$c)$t->string($c);});
        Schema::create('WBO_Products',function(Blueprint $t){$t->id('product_id');$t->string('sku')->unique();$t->string('name');$t->integer('category_id')->nullable();$t->integer('supplier_id')->nullable();$t->integer('reorder_point')->default(5);$t->boolean('is_visible')->default(false);});
        Schema::create('WBO_Categories',function(Blueprint $t){$t->id('category_id');$t->string('name');});
        Schema::create('WBO_Batches',function(Blueprint $t){$t->id('batch_id');$t->integer('product_id')->index();$t->string('batch_number');$t->integer('quantity_received');$t->integer('current_quantity');$t->dateTime('received_date');$t->date('expiry_date')->nullable();$t->unique(['product_id','batch_number']);});
        Schema::create('WBO_Transactions',function(Blueprint $t){$t->id('transaction_id');$t->integer('batch_id')->index();$t->string('transaction_type');$t->integer('quantity_change');$t->integer('order_id')->nullable();$t->integer('purchase_order_id')->nullable();$t->string('reference_note')->nullable();$t->integer('performed_by_user_id');$t->dateTime('timestamp')->index();});
        Schema::create('WBO_Notifications',function(Blueprint $t){$t->id('notification_id');foreach(['alert_tier','title','message','status']as$c)$t->string($c);$t->integer('related_product_id')->nullable();$t->integer('related_batch_id')->nullable();$t->integer('recipient_user_id');foreach(['triggered_at','acknowledged_at','resolved_at']as$c)$t->dateTime($c)->nullable();});
        Schema::create('WBO_NotificationState',function(Blueprint $t){$t->string('state_key')->primary();$t->string('state_value')->nullable();$t->dateTime('updated_at')->nullable();});
        Schema::create('WBO_AuditLogs',function(Blueprint $t){$t->id('log_id');$t->integer('user_id');foreach(['action','description','ip_address','user_agent']as$c)$t->string($c)->nullable();$t->dateTime('created_at')->nullable();});
        DB::table('WBO_Users')->insert(['user_id'=>1,'name'=>'Test operator','email'=>'operator@example.test','role'=>'Warehouse_Admin','account_status'=>'active']);
        DB::table('WBO_Categories')->insert(['category_id'=>1,'name'=>'Test category']);
        DB::table('WBO_Products')->insert([['product_id'=>1,'sku'=>'TEST-1','name'=>'Changed product','category_id'=>1,'reorder_point'=>5,'is_visible'=>false],['product_id'=>2,'sku'=>'TEST-2','name'=>'Other product','category_id'=>1,'reorder_point'=>5,'is_visible'=>false]]);
        DB::table('WBO_Batches')->insert([['batch_id'=>1,'product_id'=>1,'batch_number'=>'B-1','quantity_received'=>3,'current_quantity'=>3,'received_date'=>'2026-10-17 12:00:00','expiry_date'=>null],['batch_id'=>2,'product_id'=>2,'batch_number'=>'B-2','quantity_received'=>10,'current_quantity'=>10,'received_date'=>'2026-10-17 12:00:00','expiry_date'=>null]]);
        foreach(['system:catalog-notification-baseline'=>'ready','stock-level:1'=>'low','stock-level:2'=>'normal']as$key=>$value)DB::table('WBO_NotificationState')->insert(['state_key'=>$key,'state_value'=>$value]);
        $this->signIn('Warehouse_Admin');
    }
    protected function tearDown(): void {Carbon::setTestNow();parent::tearDown();}
    private function signIn(string $role): void {DB::table('WBO_Users')->where('user_id',1)->update(['role'=>$role]);$this->withSession(['logged_in'=>true,'user_id'=>1,'role'=>$role]);}
    public static function stockRoles(): iterable {yield ['Warehouse_Admin','/api/role-dashboard/stock-in'];yield ['Inventory_Controller','/api/role-dashboard/stock-in'];yield ['super_admin','/api/super-admin/stock-in'];}

    #[DataProvider('stockRoles')]
    public function test_stock_in_returns_only_affected_inventory_and_preserves_role_boundary(string $role,string $url): void
    {
        $this->signIn($role);DB::enableQueryLog();
        $response=$this->postJson($url,['product_id'=>1,'batch_number'=>'NEW','quantity_received'=>7]);
        $response->assertCreated()->assertJsonCount(1,'inventory_update.product_stocks')->assertJsonCount(1,'inventory_update.batches')->assertJsonPath('inventory_update.product_stocks.0.available_stock',10)->assertJsonPath('inventory_update.metrics.total_stock',20)->assertJsonPath('inventory_update.transactions.0.quantity_change',7);
        $update=$response->json('inventory_update');
        foreach(['orders','users','settings','backups','user_activity','anonymous_visitors','suppliers']as$key)$this->assertArrayNotHasKey($key,$update);
        $this->assertSame($role==='super_admin',array_key_exists('audit_logs',$update));$this->assertSame($role==='super_admin',array_key_exists('notifications',$update));
        $this->assertSame($role==='super_admin',array_key_exists('performed_by_user_id',$update['transactions'][0]));
        $this->assertArrayNotHasKey('category_id',$update['product_stocks'][0]);
        $queries=collect(DB::getQueryLog())->pluck('query')->implode("\n");
        foreach(['WBO_Orders','WBO_OrderDetails','WBO_UserSessions','WBO_SystemSettings']as$table)$this->assertStringNotContainsString($table,$queries);
        $this->assertDatabaseCount('WBO_Transactions',1);$this->assertDatabaseHas('WBO_Batches',['batch_number'=>'B-2','current_quantity'=>10]);
        if($role==='super_admin')$response->assertJsonPath('inventory_update.categories.0.total_stock',20);
    }
    public function test_adjustment_and_write_off_return_quantities_and_reject_negative_stock(): void
    {
        $this->signIn('Inventory_Controller');
        $this->postJson('/api/role-dashboard/adjust-stock',['batch_id'=>1,'quantity_change'=>2,'reference_note'=>'count correction'])->assertOk()->assertJsonPath('inventory_update.product_stocks.0.available_stock',5);
        $this->postJson('/api/role-dashboard/write-off',['batch_id'=>1,'quantity'=>2,'reason'=>'DAMAGED','reference_note'=>'test damage'])->assertOk()->assertJsonPath('inventory_update.batches.0.current_quantity',3);
        $this->postJson('/api/role-dashboard/adjust-stock',['batch_id'=>1,'quantity_change'=>-4,'reference_note'=>'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('WBO_Transactions',2);$this->assertDatabaseHas('WBO_Batches',['batch_id'=>1,'current_quantity'=>3]);
    }
    public function test_expired_stock_is_excluded_while_today_remains_available(): void
    {
        DB::table('WBO_Batches')->where('batch_id',1)->update(['expiry_date'=>'2026-10-17']);
        $this->postJson('/api/role-dashboard/stock-in',['product_id'=>1,'batch_number'=>'TODAY','quantity_received'=>2,'expiry_date'=>'2026-10-18'])->assertCreated()->assertJsonPath('inventory_update.product_stocks.0.available_stock',2)->assertJsonPath('inventory_update.metrics.total_stock',12);
    }
    public function test_scoped_expiry_sync_never_resolves_another_products_warning(): void
    {
        DB::table('WBO_Batches')->where('batch_id',2)->update(['expiry_date'=>'2026-10-25']);
        DB::table('WBO_Notifications')->insert(['alert_tier'=>'Expiry','title'=>'Other product expiry','message'=>'Existing warning','status'=>'UNREAD','related_product_id'=>2,'related_batch_id'=>2,'recipient_user_id'=>1,'triggered_at'=>now()]);
        $this->postJson('/api/role-dashboard/stock-in',['product_id'=>1,'batch_number'=>'NEW','quantity_received'=>7])->assertCreated();
        $this->assertDatabaseHas('WBO_Notifications',['related_product_id'=>2,'status'=>'UNREAD','resolved_at'=>null]);$this->assertDatabaseMissing('WBO_NotificationState',['state_key'=>'expiry:2:2026-10-25']);
    }
    public function test_receipt_updates_affected_purchase_order_rows_and_inventory(): void
    {
        Schema::create('WBO_Suppliers',function(Blueprint $t){$t->id('supplier_id');$t->string('name');});
        Schema::create('WBO_PurchaseOrders',function(Blueprint $t){$t->id('po_id');$t->string('po_number');$t->integer('supplier_id');$t->integer('created_by_user_id');$t->integer('approved_by_user_id')->nullable();$t->string('status');foreach(['created_at','approved_at','ordered_at','received_at','cancelled_at']as$c)$t->dateTime($c)->nullable();});
        Schema::create('WBO_PurchaseOrderDetails',function(Blueprint $t){$t->id('po_detail_id');$t->integer('po_id');$t->integer('product_id');$t->integer('quantity_ordered');$t->integer('quantity_received');$t->decimal('unit_cost');});
        DB::table('WBO_Suppliers')->insert(['supplier_id'=>1,'name'=>'Test supplier']);DB::table('WBO_PurchaseOrders')->insert(['po_id'=>1,'po_number'=>'PO-1','supplier_id'=>1,'created_by_user_id'=>1,'status'=>'ORDERED','created_at'=>now()]);DB::table('WBO_PurchaseOrderDetails')->insert(['po_detail_id'=>1,'po_id'=>1,'product_id'=>1,'quantity_ordered'=>5,'quantity_received'=>0,'unit_cost'=>100]);
        $this->postJson('/api/role-dashboard/purchase-orders/1/receive',['po_detail_id'=>1,'batch_number'=>'PO-PART','quantity_received'=>2])->assertCreated()->assertJsonPath('inventory_update.purchase_orders.0.status','PARTIALLY_RECEIVED')->assertJsonPath('inventory_update.purchase_orders.0.quantity_received',2);
        $this->postJson('/api/role-dashboard/purchase-orders/1/receive',['po_detail_id'=>1,'batch_number'=>'PO-FINAL','quantity_received'=>3])->assertCreated()->assertJsonPath('inventory_update.purchase_orders.0.status','RECEIVED')->assertJsonPath('inventory_update.product_stocks.0.available_stock',8);
    }
    public function test_inventory_import_delta_includes_successful_rows_and_skips_invalid_rows(): void
    {
        Schema::create('WBO_DataImports',function(Blueprint $t){$t->id('import_id');foreach(['uploaded_by_user_id','total_rows','successful_rows','failed_rows']as$c)$t->integer($c)->default(0);foreach(['import_type','file_type','original_filename','stored_file_path','status']as$c)$t->string($c)->nullable();foreach(['started_at','completed_at','created_at']as$c)$t->dateTime($c)->nullable();});
        Schema::create('WBO_DataImportErrors',function(Blueprint $t){$t->id('import_error_id');$t->integer('import_id');$t->integer('row_number')->nullable();foreach(['sheet_name','field_name','raw_value','raw_row_data','error_message']as$c)$t->text($c)->nullable();$t->dateTime('created_at')->nullable();});
        $file=UploadedFile::fake()->createWithContent('inventory.csv',"sku,batch_number,quantity_received\nTEST-1,IMPORT-OK,4\nUNKNOWN,IMPORT-BAD,2\n");
        $this->postJson('/api/data-imports',['import_type'=>'INVENTORY','file'=>$file])->assertCreated()->assertJsonPath('import.status','PARTIAL')->assertJsonPath('import.successful_rows',1)->assertJsonPath('import.failed_rows',1)->assertJsonCount(1,'inventory_update.batches')->assertJsonPath('inventory_update.product_stocks.0.available_stock',7);
        $this->assertDatabaseMissing('WBO_Batches',['batch_number'=>'IMPORT-BAD']);
    }
    public function test_query_count_and_changed_row_payload_do_not_grow_with_unrelated_catalog_size(): void
    {
        $service=app(InventoryUpdateService::class);DB::enableQueryLog();$small=$service->forBatches([1]);$count=count(DB::getQueryLog());
        $rows=[];for($i=3;$i<=302;$i++)$rows[]=['product_id'=>$i,'sku'=>'SCALE-'.$i,'name'=>'Unrelated '.$i,'category_id'=>1,'reorder_point'=>5,'is_visible'=>false];
        DB::table('WBO_Products')->insert($rows);DB::flushQueryLog();$large=app(InventoryUpdateService::class)->forBatches([1]);
        $this->assertSame($count,count(DB::getQueryLog()));$this->assertCount(1,$large['product_stocks']);$this->assertCount(1,$large['batches']);$this->assertEquals($small['product_stocks'],$large['product_stocks']);$this->assertSame(300,$large['metrics']['out_of_stock']);
    }
    public function test_scoped_save_initializes_the_complete_stock_alert_baseline_once(): void
    {
        DB::table('WBO_NotificationState')->delete();
        $this->postJson('/api/role-dashboard/stock-in',['product_id'=>1,'batch_number'=>'BASELINE','quantity_received'=>7])->assertCreated();
        $this->assertDatabaseHas('WBO_NotificationState',['state_key'=>'stock-level:2','state_value'=>'normal']);
        $this->assertDatabaseHas('WBO_NotificationState',['state_key'=>'system:catalog-notification-baseline','state_value'=>'ready']);
    }

    public function test_back_in_stock_notifications_and_resolution_still_work_without_duplicates(): void
    {
        DB::table('WBO_Products')->where('product_id',1)->update(['is_visible'=>true]);
        DB::table('WBO_Batches')->where('batch_id',1)->update(['current_quantity'=>0]);
        DB::table('WBO_NotificationState')->where('state_key','stock-level:1')->update(['state_value'=>'out']);
        DB::table('WBO_NotificationState')->insert(['state_key'=>'published:1','state_value'=>'seen']);
        DB::table('WBO_Users')->insert(['user_id'=>2,'name'=>'Test customer','email'=>'customer@example.test','role'=>'System_User','account_status'=>'active']);
        DB::table('WBO_Notifications')->insert(['alert_tier'=>'Red','title'=>'Out of stock','message'=>'Existing warning','status'=>'UNREAD','related_product_id'=>1,'recipient_user_id'=>1,'triggered_at'=>now()]);
        $this->postJson('/api/role-dashboard/stock-in',['product_id'=>1,'batch_number'=>'BACK','quantity_received'=>7])->assertCreated();
        $this->assertDatabaseHas('WBO_Notifications',['alert_tier'=>'Red','related_product_id'=>1,'status'=>'RESOLVED']);
        app(\App\Services\Shared\NotificationService::class)->syncOperationalAlerts([1]);
        $this->assertSame(1,DB::table('WBO_Notifications')->where('recipient_user_id',2)->where('title','Product back in stock')->count());
    }

    public function test_full_alert_check_reads_state_in_batches_instead_of_once_per_product(): void
    {
        DB::enableQueryLog();app(\App\Services\Shared\NotificationService::class)->syncOperationalAlerts();$count=count(DB::getQueryLog());
        $products=[];$states=[];
        for($i=3;$i<=302;$i++) {
            $products[]=['product_id'=>$i,'sku'=>'ALERT-'.$i,'name'=>'Unrelated '.$i,'reorder_point'=>5,'is_visible'=>false];
            $states[]=['state_key'=>'stock-level:'.$i,'state_value'=>'out'];
        }
        DB::table('WBO_Products')->insert($products);DB::table('WBO_NotificationState')->insert($states);DB::flushQueryLog();
        app(\App\Services\Shared\NotificationService::class)->syncOperationalAlerts();
        $this->assertSame($count,count(DB::getQueryLog()));
    }

    public function test_optional_refresh_failure_does_not_report_a_committed_stock_in_as_failed(): void
    {
        $this->signIn('super_admin');
        Schema::drop('WBO_Categories'); // Simulate a read failure after the inventory transaction commits.
        $this->postJson('/api/super-admin/stock-in',['product_id'=>1,'batch_number'=>'SAVED','quantity_received'=>4])
            ->assertCreated()->assertJsonPath('inventory_update',null);
        $this->assertDatabaseHas('WBO_Batches',['batch_number'=>'SAVED','current_quantity'=>4]);
        $this->assertDatabaseCount('WBO_Transactions',1);
    }

    public function test_stock_trend_uses_one_aggregate_query_and_preserves_week_boundaries(): void
    {
        $start=now()->startOfWeek()->subWeeks(5);
        foreach([$start->copy()->subSecond(),$start,$start->copy()->addWeek(),$start->copy()->addWeeks(6)]as$index=>$time)DB::table('WBO_Transactions')->insert(['batch_id'=>1,'transaction_type'=>'RECEIVE','quantity_change'=>$index+1,'performed_by_user_id'=>1,'timestamp'=>$time]);
        DB::enableQueryLog();$trend=app(InventoryUpdateService::class)->stockTrend();$this->assertCount(1,DB::getQueryLog());$this->assertCount(6,$trend);$this->assertSame(2,$trend[0]['net_movement']);$this->assertSame(3,$trend[1]['net_movement']);$this->assertSame(0,$trend[5]['net_movement']);
    }
}
