<?php
namespace Tests\Feature;

use App\Http\Middleware\EnforceIdleSession;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Disposable SQLite fixtures; never runs application migrations or connects to MySQL. */
class DashboardScopedRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','session.driver'=>'array','cache.default'=>'array','app.key'=>'base64:'.base64_encode(str_repeat('t',32))]);
        DB::purge('sqlite'); Carbon::setTestNow('2026-10-18 12:00:00');
        $this->withoutMiddleware(EnforceIdleSession::class); Storage::fake('local');
        $this->table('WBO_Users','user_id',['name','email','role','account_status','contact_number','email_verified_at','last_seen_at','created_at']);
        $this->table('WBO_Products','product_id',['sku','name','description','abc_class','created_at','updated_at'],['supplier_id','category_id','reorder_point','is_seasonal','is_visible','is_featured','unit_cost','unit_price']);
        $this->table('WBO_Categories','category_id',['name','description'],['is_active']);
        $this->table('WBO_Suppliers','supplier_id',['name','contact_number','email','address','supplier_status'],['lead_time_days']);
        $this->table('WBO_Batches','batch_id',['batch_number','received_date','expiry_date'],['product_id','quantity_received','current_quantity']);
        $this->table('WBO_ProductImages','image_id',['image_path'],['product_id','is_primary']);
        $this->table('WBO_Orders','order_id',['customer_name','customer_contact','order_date','status','fulfilled_at','cancelled_at','payment_method','payment_status','payment_reference_number','paid_at','delivery_street_address','delivery_email','delivery_full_name','delivery_contact_number','delivery_barangay','delivery_city_municipality','delivery_province','delivery_postal_code','delivery_notes'],['customer_user_id','total_amount']);
        $this->table('WBO_OrderDetails','order_detail_id',[],['order_id','product_id','quantity','unit_price']);
        $this->table('WBO_Transactions','transaction_id',['transaction_type','timestamp','reference_note'],['batch_id','quantity_change','order_id','purchase_order_id','performed_by_user_id']);
        $this->table('WBO_PurchaseOrders','po_id',['po_number','status','created_at','approved_at','ordered_at','received_at','cancelled_at'],['supplier_id','created_by_user_id','approved_by_user_id']);
        $this->table('WBO_PurchaseOrderDetails','po_detail_id',[],['po_id','product_id','quantity_ordered','quantity_received','unit_cost']);
        $this->table('WBO_Notifications','notification_id',['alert_tier','title','message','triggered_at','status','acknowledged_at','resolved_at'],['related_product_id','related_batch_id','recipient_user_id']);
        $this->table('WBO_AuditLogs','log_id',['action','description','ip_address','user_agent','created_at'],['user_id']);
        $this->table('WBO_UserSessions','id',['session_id','logged_in_at','last_activity_at','logged_out_at'],['user_id','is_active']);
        $this->table('WBO_AnonymousLandingVisits','visit_id',['visit_date']);
        DB::table('WBO_Users')->insert([
            ['user_id'=>1,'name'=>'Admin','email'=>'admin@example.test','role'=>'super_admin','account_status'=>'active','last_seen_at'=>now()],
            ['user_id'=>2,'name'=>'Customer','email'=>'customer@example.test','role'=>'System_User','account_status'=>'active','last_seen_at'=>now()],
            ['user_id'=>3,'name'=>'Other','email'=>'other@example.test','role'=>'System_User','account_status'=>'disabled','last_seen_at'=>null],
        ]);
        DB::table('WBO_Suppliers')->insert(['supplier_id'=>1,'name'=>'Fixture supplier','supplier_status'=>'ACTIVE','lead_time_days'=>2]);
        DB::table('WBO_Categories')->insert(['category_id'=>1,'name'=>'Fixture category','is_active'=>1]);
        DB::table('WBO_Products')->insert(['product_id'=>1,'sku'=>'FIXTURE','name'=>'Fixture product','supplier_id'=>1,'category_id'=>1,'reorder_point'=>5,'unit_cost'=>10,'unit_price'=>15,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('WBO_Batches')->insert(['batch_id'=>1,'product_id'=>1,'batch_number'=>'FIXTURE-1','quantity_received'=>3,'current_quantity'=>3,'received_date'=>now(),'expiry_date'=>now()->addDays(3)->toDateString()]);
        DB::table('WBO_Orders')->insert([
            ['order_id'=>1,'customer_user_id'=>2,'customer_name'=>'Customer','customer_contact'=>'123','status'=>'PENDING','total_amount'=>30,'order_date'=>now(),'payment_method'=>'CASH_ON_DELIVERY','payment_status'=>'PENDING'],
            ['order_id'=>2,'customer_user_id'=>3,'customer_name'=>'Other','customer_contact'=>'456','status'=>'FULFILLED','total_amount'=>15,'order_date'=>now(),'payment_method'=>'CASH_ON_DELIVERY','payment_status'=>'PAID'],
        ]);
        DB::table('WBO_OrderDetails')->insert([
            ['order_detail_id'=>1,'order_id'=>1,'product_id'=>1,'quantity'=>2,'unit_price'=>15],
            ['order_detail_id'=>2,'order_id'=>2,'product_id'=>1,'quantity'=>1,'unit_price'=>15],
        ]);
        DB::table('WBO_Transactions')->insert(['transaction_id'=>1,'batch_id'=>1,'quantity_change'=>3,'transaction_type'=>'RECEIVE','timestamp'=>now(),'performed_by_user_id'=>1]);
        DB::table('WBO_PurchaseOrders')->insert(['po_id'=>1,'po_number'=>'FIXTURE-PO','supplier_id'=>1,'created_by_user_id'=>1,'status'=>'PENDING_APPROVAL','created_at'=>now()]);
        DB::table('WBO_PurchaseOrderDetails')->insert(['po_detail_id'=>1,'po_id'=>1,'product_id'=>1,'quantity_ordered'=>10,'quantity_received'=>0,'unit_cost'=>10]);
        DB::table('WBO_Notifications')->insert(['notification_id'=>1,'title'=>'Fixture notice','message'=>'Fixture','recipient_user_id'=>1,'status'=>'UNREAD','triggered_at'=>now()]);
        DB::table('WBO_AuditLogs')->insert(['log_id'=>1,'user_id'=>1,'action'=>'USER_UPDATED','created_at'=>now()]);
        DB::table('WBO_UserSessions')->insert(['session_id'=>'fixture','user_id'=>2,'is_active'=>1,'logged_in_at'=>now(),'last_activity_at'=>now()]);
        $this->login('super_admin');
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function table(string $table,string $id,array $text,array $numeric=[]): void
    {
        Schema::create($table,function(Blueprint $t)use($id,$text,$numeric){$t->id($id);foreach($text as$c)$t->string($c)->nullable();foreach($numeric as$c)$t->integer($c)->nullable();});
    }
    private function login(string $role,int $id=1): void {$this->withSession(['logged_in'=>true,'user_id'=>$id,'role'=>$role]);}
    public static function adminSections(): iterable {foreach(['current_user','products','batches','suppliers','orders','transactions','purchase_orders','users','notifications','audit_logs','categories','stock_trend','user_activity','anonymous_visitors','settings','backups']as$s)yield $s=>[$s];}
    #[DataProvider('adminSections')]
    public function test_admin_partial_matches_initial_data(string $section): void
    {
        $full=$this->getJson('/api/super-admin/dashboard-data')->assertOk()->json();
        $part=$this->getJson('/api/super-admin/dashboard-data?sections='.$section)->assertOk()->json('dashboard_update');
        $this->assertEquals($full[$section],$part[$section]);$this->assertEqualsCanonicalizing([$section,'metrics'],array_keys($part));
        foreach($part['metrics']as$key=>$value)$this->assertEquals($full['metrics'][$key],$value,$key);
    }
    public static function operationalSections(): iterable {foreach(['Operations_Manager','Purchasing_Manager','Purchasing_Staff','Warehouse_Admin','Inventory_Controller']as$r)foreach(['suppliers','products','purchase_orders','orders','batches','transactions']as$s)yield "$r $s"=>[$r,$s];}
    #[DataProvider('operationalSections')]
    public function test_operational_partial_counters_match_initial_dashboard(string $role,string $section): void
    {
        $this->login($role);$url='/api/role-dashboard/'.$role;
        $full=$this->getJson($url)->assertOk()->json();$part=$this->getJson($url.'?sections='.$section)->assertOk()->json('dashboard_update');
        $this->assertEquals($full[$section],$part[$section]);foreach($part['metrics']as$key=>$value)$this->assertEquals($full['metrics'][$key],$value,$key);
        foreach($part['alerts']as$alert)$this->assertContains($alert,$full['alerts']);$this->assertArrayNotHasKey('users',$part);$this->assertArrayNotHasKey('settings',$part);
    }
    public static function salesSections(): iterable {foreach(['Sales_Manager','Sales_Staff']as$r)foreach(['orders','products','customers','product_performance']as$s)yield "$r $s"=>[$r,$s];}
    #[DataProvider('salesSections')]
    public function test_sales_partial_matches_initial_domain(string $role,string $section): void
    {
        $this->login($role);$url='/api/sales-role/'.$role;
        $full=$this->getJson($url)->assertOk()->json();$part=$this->getJson($url.'?sections='.$section)->assertOk()->json('dashboard_update');
        $this->assertEquals($full[$section],$part[$section]);foreach($part['metrics']as$key=>$value)$this->assertEquals($full['metrics'][$key],$value);
    }
    public function test_supplier_refresh_avoids_unrelated_queries(): void
    {
        DB::enableQueryLog();$this->getJson('/api/super-admin/dashboard-data?sections=suppliers')->assertOk();
        $queries=collect(DB::getQueryLog())->pluck('query')->implode("\n");
        foreach(['WBO_Batches','WBO_Orders','WBO_UserSessions','WBO_AnonymousLandingVisits','WBO_SystemSettings','WBO_Transactions']as$t)$this->assertStringNotContainsString($t,$queries);
    }
    public function test_authorization_and_validation_cannot_be_bypassed(): void
    {
        $this->getJson('/api/super-admin/dashboard-data?sections=unknown')->assertUnprocessable();$this->getJson('/api/super-admin/dashboard-data?sections[]=users')->assertUnprocessable();
        $this->login('System_User',2);$this->getJson('/api/super-admin/dashboard-data?sections=users')->assertForbidden();$this->getJson('/api/role-dashboard/Warehouse_Admin?sections=suppliers')->assertForbidden();$this->getJson('/api/sales-role/Sales_Staff?sections=orders')->assertForbidden();
        $this->flushSession();$this->getJson('/api/super-admin/dashboard-data?sections=users')->assertUnauthorized();
    }
    public function test_preview_scoped_read_preserves_authorization(): void
    {
        $this->getJson('/api/role-dashboard/Warehouse_Admin?sections=suppliers')->assertForbidden();$this->getJson('/api/role-dashboard/Warehouse_Admin?preview=1&sections=suppliers')->assertOk();$this->getJson('/api/sales-role/Sales_Staff?preview=1&sections=orders')->assertOk();
    }
    public function test_customer_lookup_only_returns_owned_order_and_global_stats(): void
    {
        $this->login('System_User',2);$this->getJson('/api/user/orders?order_id=1&include_stats=1')->assertOk()->assertJsonPath('partial_order',true)->assertJsonCount(1,'orders')->assertJsonPath('stats.total_orders',1);
        $this->getJson('/api/user/orders?order_id=2&include_stats=1')->assertOk()->assertJsonCount(0,'orders');$this->getJson('/api/user/orders?order_id=-1')->assertUnprocessable();$this->getJson('/api/user/orders')->assertOk()->assertJsonCount(1,'orders')->assertJsonMissingPath('partial_order');
    }
    public function test_user_admin_partial_summary_keeps_global_counts(): void
    {
        $this->login('User_Admin');$full=$this->getJson('/api/user-admin/dashboard')->assertOk()->json();$part=$this->getJson('/api/user-admin/dashboard?user_id=2')->assertOk()->assertJsonPath('partial_users',true)->assertJsonCount(1,'users')->json();
        $this->assertEquals($full['metrics'],$part['metrics']);$this->assertEquals($full['role_counts'],$part['role_counts']);$this->assertEquals($full['status_counts'],$part['status_counts']);$this->getJson('/api/user-admin/dashboard?user_id=1')->assertOk()->assertJsonCount(0,'users');
    }
    public function test_sales_items_are_limited_to_the_existing_order_window(): void
    {
        for($i=3;$i<=155;$i++){DB::table('WBO_Orders')->insert(['order_id'=>$i,'customer_user_id'=>2,'status'=>'PENDING','order_date'=>now()]);DB::table('WBO_OrderDetails')->insert(['order_detail_id'=>$i,'order_id'=>$i,'product_id'=>1,'quantity'=>1,'unit_price'=>15]);}
        $this->login('Sales_Manager');DB::enableQueryLog();$result=$this->getJson('/api/sales-role/Sales_Manager?sections=orders')->assertOk()->json('dashboard_update.orders');$this->assertCount(150,$result);$this->assertCount(1,$result[0]['items']);$queries=collect(DB::getQueryLog())->pluck('query')->implode("\n");$this->assertStringContainsString('visible_orders',$queries);$this->assertStringNotContainsString('WBO_Batches',$queries);
    }
    public function test_scoped_reads_do_less_work_than_the_initial_admin_load(): void
    {
        $results = [];
        foreach ([
            'initial' => '/api/super-admin/dashboard-data',
            'supplier_edit' => '/api/super-admin/dashboard-data?sections=suppliers,audit_logs',
            'notification_read' => '/api/super-admin/dashboard-data?sections=notifications,audit_logs',
        ] as $name => $url) {
            DB::enableQueryLog(); DB::flushQueryLog();
            $response = $this->getJson($url)->assertOk();
            $results[$name] = ['queries' => count(DB::getQueryLog()), 'json_bytes' => strlen($response->getContent())];
        }
        foreach (['supplier_edit', 'notification_read'] as $name) {
            $this->assertLessThan($results['initial']['queries'], $results[$name]['queries']);
            $this->assertLessThan($results['initial']['json_bytes'], $results[$name]['json_bytes']);
        }
        if ($output = getenv('WBO_REFRESH_PROFILE_OUTPUT')) {
            file_put_contents($output, json_encode([
                'scope' => 'Disposable SQLite fixture, not production latency or MySQL EXPLAIN.',
                'results' => $results,
            ], JSON_PRETTY_PRINT).PHP_EOL);
        }
    }

}
