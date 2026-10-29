<?php

namespace Tests\Integration;

use App\Exceptions\RuleValidationException;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Procurement;
use App\Models\ProcurementOrder;
use App\Service\IfakaClient;
use App\Service\OrderProcessService;
use App\Service\ProcurementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\ProcurementMysqlClient;
use Tests\TestCase;

// Explicitly invoked; creates and removes only randomly named databases on a local MySQL server.
class ProcurementMysqlTest extends TestCase
{
    private $admin;
    private $connection;
    private $databases = [];
    private $workers = [];
    private $legacyTables;
    private $legacyBeforeFeature;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PROCUREMENT_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Set PROCUREMENT_MYSQL_TEST=1 to run the isolated MySQL tests.');
        }
        $host = getenv('PROCUREMENT_MYSQL_HOST') ?: '127.0.0.1';
        $this->assertContains($host, ['127.0.0.1', 'localhost', '::1'], 'Use a local, disposable MySQL server.');
        $this->connection = [
            'driver' => 'mysql', 'host' => $host, 'port' => getenv('PROCUREMENT_MYSQL_PORT') ?: '3307',
            'username' => getenv('PROCUREMENT_MYSQL_USER') ?: 'root',
            'password' => getenv('PROCUREMENT_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
        ];
        $this->admin = new PDO('mysql:host=' . $host . ';port=' . $this->connection['port'] . ';charset=utf8mb4',
            $this->connection['username'], $this->connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->connection['database'] = $this->newDatabase();
        config(['database.default' => 'procurement_mysql',
            'database.connections.procurement_mysql' => $this->connection,
            'app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        Queue::fake();
        Cache::put('system-setting', ['ifaka_app_id' => 'mysql-test', 'ifaka_request_prefix' => 'MT']);
        $this->app->instance(IfakaClient::class, new ProcurementMysqlClient());
        // Use the real legacy schema, including its indexes, required columns and character sets.
        DB::unprepared(file_get_contents(database_path('sql/install.sql')));
        $this->legacyTables = array_column(DB::select('SHOW TABLES'), 'Tables_in_' . $this->connection['database']);
        $this->legacyBeforeFeature = $this->snapshot($this->legacyTables);
        DB::unprepared(file_get_contents(database_path('sql/create_procurements_only.sql')));
        Schema::create('procurement_test_calls', function (Blueprint $table) {
            $table->increments('id');
            $table->string('endpoint');
        });
        require_once database_path('migrations/2026_10_28_000001_add_procurement.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) { $worker->stop(1); }
        }
        if ($this->admin) {
            DB::purge('procurement_mysql');
            DB::purge('procurement_migration_compare');
            foreach ($this->databases as $database) {
                // Names are generated here, never taken from a developer's application configuration.
                if (preg_match('/^dujiaoka_procurement_test_[a-f0-9]{16}$/D', $database)) {
                    $this->admin->exec('DROP DATABASE `' . $database . '`');
                }
            }
        }
        parent::tearDown();
    }

    private function newDatabase(): string
    {
        $database = 'dujiaoka_procurement_test_' . bin2hex(random_bytes(8));
        $this->admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->databases[] = $database;
        return $database;
    }

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->toArray();
            usort($rows, function ($left, $right) { return strcmp(json_encode($left), json_encode($right)); });
            $snapshot[$table] = ['ddl' => (array) DB::selectOne('SHOW CREATE TABLE `' . $table . '`'), 'rows' => $rows];
        }
        return $snapshot;
    }

    private function order(?Goods $goods = null): Order
    {
        if (!$goods) {
            $goods = new Goods();
            $goods->forceFill(['group_id' => 1, 'gd_name' => 'MySQL test', 'gd_description' => 'Test',
                'gd_keywords' => 'Test', 'actual_price' => '10.00', 'type' => 1])->save();
            $goods->procurementSettings()->create(['procurement_enabled' => true,
                'supplier_code' => 'product', 'supplier_sku' => '{}', 'procurement_max_cost' => '8.00']);
            $goods = $goods->fresh();
        }
        $process = new OrderProcessService();
        $process->setGoods($goods);
        $process->setBuyAmount(1);
        $process->setEmail('buyer@example.invalid');
        $process->setSearchPwd('test');
        $process->setBuyIP('127.0.0.1');
        $process->setPayID(1);
        return $process->createOrder();
    }

    private function pay(Order $order): void
    {
        (new OrderProcessService())->completedOrder($order->order_sn, 10, 'mysql-payment');
    }

    private function worker(string $action, array $arguments = []): Process
    {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file()) { $command = array_merge($command, ['-c', php_ini_loaded_file()]); }
        $command[] = base_path('tests/Support/procurement-mysql-worker.php');
        $worker = new Process($command, base_path(), [
            'APP_ENV' => 'testing', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array', 'MAIL_DRIVER' => 'array',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
        ], json_encode(['connection' => $this->connection, 'action' => $action] + $arguments), 20);
        $this->workers[] = $worker;
        $worker->start();
        return $worker;
    }

    private function awaitReady(array $workers): void
    {
        $deadline = microtime(true) + 8;
        foreach ($workers as $worker) {
            while (strpos($worker->getOutput(), 'ready') === false && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ready', $worker->getOutput(), $worker->getErrorOutput());
            $this->assertTrue($worker->isRunning(), 'Worker should wait for the held database lock.');
        }
    }

    private function finish(array $workers): void
    {
        foreach ($workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertStringContainsString('done', $worker->getOutput());
        }
    }

    public function test_manual_sql_and_migration_match_and_preserve_existing_tables_and_data()
    {
        $this->assertEquals($this->legacyBeforeFeature, $this->snapshot($this->legacyTables));
        $order = $this->order();
        $this->pay($order);
        $tables = array_merge($this->legacyTables, ['procurement_goods', 'procurement_orders', 'procurements']);
        $before = $this->snapshot($tables);
        DB::unprepared(file_get_contents(database_path('sql/create_procurements_only.sql')));
        (new \AddProcurement())->up();
        $this->assertEquals($before, $this->snapshot($tables));

        $comparison = $this->connection;
        $comparison['database'] = $this->newDatabase();
        config(['database.connections.procurement_migration_compare' => $comparison,
            'database.default' => 'procurement_migration_compare']);
        try {
            (new \AddProcurement())->up();
            foreach (['procurement_goods', 'procurement_orders', 'procurements'] as $table) {
                $manualDdl = $this->admin->query('SHOW CREATE TABLE `' . $this->connection['database'] . '`.`' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
                $migrationDdl = $this->admin->query('SHOW CREATE TABLE `' . $comparison['database'] . '`.`' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
                $this->assertSame(preg_replace('/ AUTO_INCREMENT=\d+/', '', $manualDdl), $migrationDdl);
            }
        } finally {
            config(['database.default' => 'procurement_mysql']);
        }
        (new \AddProcurement())->down();
        $this->assertEquals(array_intersect_key($before, array_flip($this->legacyTables)), $this->snapshot($this->legacyTables));
    }

    public function test_concurrent_payment_callbacks_create_one_durable_purchase()
    {
        $order = $this->order();
        DB::beginTransaction();
        try {
            DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $workers = [$this->worker('pay', ['order_sn' => $order->order_sn]), $this->worker('pay', ['order_sn' => $order->order_sn])];
            $this->awaitReady($workers);
        } finally { DB::rollBack(); }
        $this->finish($workers);
        $this->assertSame(1, Procurement::count());
        $this->assertSame(1, ProcurementOrder::count());
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('mysql-payment', $order->fresh()->trade_no);
        $this->assertEquals(1, $order->goods->fresh()->sales_volume);
        $this->assertSame(0, DB::table('procurement_test_calls')->count());
    }

    public function test_two_orders_cannot_deliver_the_same_local_card()
    {
        $first = $this->order();
        $second = $this->order($first->goods);
        $card = new Carmis();
        $card->forceFill(['goods_id' => $first->goods_id, 'carmi' => 'ONLY_LOCAL_CARD'])->save();
        DB::beginTransaction();
        try {
            DB::table('carmis')->where('id', $card->id)->lockForUpdate()->first();
            $workers = [$this->worker('pay', ['order_sn' => $first->order_sn]), $this->worker('pay', ['order_sn' => $second->order_sn])];
            $this->awaitReady($workers);
        } finally { DB::rollBack(); }
        $this->finish($workers);
        $this->assertSame(1, Order::where('status', Order::STATUS_COMPLETED)->where('info', 'ONLY_LOCAL_CARD')->count());
        $this->assertSame(1, Order::where('status', Order::STATUS_PROCESSING)->count());
        $this->assertSame(1, Procurement::count());
        $this->assertEquals(Carmis::STATUS_SOLD, $card->fresh()->status);
        $this->assertEquals(2, $first->goods->fresh()->sales_volume);
        $this->assertNotNull($first->fresh()->paid_at);
        $this->assertNotNull($second->fresh()->paid_at);
    }

    public function test_concurrent_workers_submit_once_and_bind_one_sold_card()
    {
        $order = $this->order();
        $this->pay($order);
        $id = Procurement::first()->id;
        DB::beginTransaction();
        try {
            DB::table('procurements')->where('id', $id)->lockForUpdate()->first();
            $workers = [$this->worker('process', ['id' => $id]), $this->worker('process', ['id' => $id])];
            $this->awaitReady($workers);
        } finally { DB::rollBack(); }
        $this->finish($workers);
        $this->assertSame(1, DB::table('procurement_test_calls')->where('endpoint', 'trade')->count());
        $this->assertSame('delivered', Procurement::first()->state);
        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(1, Carmis::count());
        $this->assertEquals(Carmis::first()->id, $order->fresh()->procurementOrder->carmi_id);
        $this->assertEquals(Carmis::STATUS_SOLD, Carmis::first()->status);
    }

    public function test_enqueue_failure_rolls_back_the_real_mysql_payment_transaction()
    {
        $order = $this->order();
        Procurement::creating(function () { throw new \RuntimeException('Simulated storage failure'); });
        try {
            $this->pay($order);
            $this->fail('Expected payment rollback');
        } catch (RuleValidationException $exception) {
            $this->assertNull($order->fresh()->paid_at);
            $this->assertEquals(Order::STATUS_WAIT_PAY, $order->fresh()->status);
            $this->assertSame('', $order->fresh()->trade_no);
            $this->assertEquals(0, $order->goods->fresh()->sales_volume);
            $this->assertSame(0, Procurement::count());
        } finally { Procurement::flushEventListeners(); }
    }

    public function test_a_new_process_recovers_card_binding_without_another_purchase()
    {
        $order = $this->order();
        $this->pay($order);
        ProcurementOrder::saving(function ($mapping) {
            if ($mapping->carmi_id !== null) { throw new \RuntimeException('Simulated binding failure'); }
        });
        try {
            $this->app->make(ProcurementService::class)->process(Procurement::first()->id);
            $this->assertSame('received', Procurement::first()->state);
            $this->assertSame(0, Carmis::count());
            $this->assertNull($order->fresh()->procurementOrder->carmi_id);
        } finally { ProcurementOrder::flushEventListeners(); }
        $this->finish([$this->worker('recover')]);
        $this->finish([$this->worker('recover')]);
        $this->assertSame(1, DB::table('procurement_test_calls')->where('endpoint', 'trade')->count());
        $this->assertSame('delivered', Procurement::first()->state);
        $this->assertSame(1, Carmis::count());
        $this->assertEquals(Carmis::first()->id, $order->fresh()->procurementOrder->carmi_id);
        $this->assertSame(Procurement::first()->secret, $order->fresh()->info);
    }
}
