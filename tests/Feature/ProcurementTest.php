<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Procurement;
use App\Models\ProcurementGoods;
use App\Models\ProcurementOrder;
use App\Jobs\ProcurementFailedPush;
use App\Service\IfakaClient;
use App\Service\OrderProcessService;
use App\Service\OrderService;
use App\Service\ProcurementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    private $legacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
        Queue::fake();
        Schema::create('goods', function (Blueprint $t) {
            $t->increments('id'); $t->string('gd_name')->default('Test');
            $t->integer('group_id')->default(1);
            $t->string('gd_description')->default('Test'); $t->string('gd_keywords')->default('Test');
            $t->text('picture')->nullable(); $t->text('description')->nullable(); $t->text('buy_prompt')->nullable();
            $t->decimal('retail_price', 10, 2)->default(10); $t->decimal('actual_price', 10, 2)->default(10);
            $t->text('wholesale_price_cnf')->nullable(); $t->text('other_ipu_cnf')->nullable();
            $t->text('api_hook')->nullable(); $t->integer('ord')->default(1);
            $t->integer('type')->default(1); $t->integer('is_open')->default(1);
            $t->integer('in_stock')->default(0); $t->integer('buy_limit_num')->default(0);
            $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn')->unique(); $t->integer('goods_id');
            $t->integer('status')->default(1); $t->integer('type')->default(1);
            $t->integer('buy_amount')->default(1); $t->decimal('actual_price', 10, 2)->default(10);
            $t->string('trade_no')->nullable(); $t->text('info')->nullable();
            $t->string('email')->default('test@example.invalid'); $t->string('title')->default('Test');
            $t->integer('coupon_id')->default(0); $t->integer('pay_id')->nullable();
            $t->decimal('goods_price', 10, 2)->default(10); $t->decimal('total_price', 10, 2)->default(10);
            $t->decimal('coupon_discount_price', 10, 2)->default(0); $t->decimal('wholesale_discount_price', 10, 2)->default(0);
            $t->string('search_pwd')->default(''); $t->string('buy_ip')->default('127.0.0.1');
            $t->integer('coupon_ret_back')->default(0);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('goods_id'); $t->integer('status')->default(1);
            $t->boolean('is_loop')->default(false); $t->text('carmi'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('coupons', function (Blueprint $t) { $t->increments('id'); $t->softDeletes(); });
        Schema::create('pays', function (Blueprint $t) { $t->increments('id'); $t->softDeletes(); });
        Schema::create('coupons_goods', function (Blueprint $t) { $t->integer('goods_id'); $t->integer('coupons_id'); });
        Schema::create('goods_group', function (Blueprint $t) {
            $t->increments('id'); $t->string('gp_name')->default('Test');
            $t->integer('is_open')->default(1); $t->integer('ord')->default(1); $t->timestamps(); $t->softDeletes();
        });
        DB::table('goods_group')->insert(['id' => 1, 'gp_name' => 'Test']);
        $this->legacySchema = $this->readLegacySchema();
        require_once database_path('migrations/2026_10_28_000001_add_procurement.php');
        (new \AddProcurement())->up();
        Cache::put('system-setting', [
            'ifaka_app_id' => 'test', 'ifaka_request_prefix' => 'TEST',
            'customer_service_wechat' => 'test-support',
        ]);
        $notifier = \Mockery::mock(OrderProcessService::class)->makePartial();
        $notifier->shouldReceive('notifyCompletedOrder')->andReturnNull();
        $this->app->instance('Service\OrderProcessService', $notifier);
    }

    private function order(array $attributes = []): Order
    {
        $goods = new Goods();
        $goods->save();
        $goods->procurementSettings()->create(['procurement_enabled' => true, 'supplier_code' => 'product',
            'supplier_sku' => '{"版本":"标准"}', 'procurement_max_cost' => '8.00']);
        $mappingFields = ['procurement_enabled', 'procurement_config', 'paid_at', 'carmi_id'];
        $order = new Order();
        $order->forceFill(array_merge([
            'goods_id' => $goods->id, 'order_sn' => bin2hex(random_bytes(8)), 'type' => 1,
            'buy_amount' => 1, 'actual_price' => '10.00', 'status' => 1,
        ], Arr::except($attributes, $mappingFields)))->save();
        $order->procurementOrder()->create(array_merge([
            'procurement_enabled' => true,
            'procurement_config' => ['code' => 'product', 'race' => '', 'sku' => ['版本' => '标准'],
                'max_cost' => '8.00', 'app_id' => 'test'],
        ], Arr::only($attributes, $mappingFields)));
        return $order->fresh();
    }

    private function readLegacySchema(): array
    {
        return DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master
            WHERE tbl_name NOT IN ('procurement_goods', 'procurement_orders', 'procurements')
            ORDER BY type, name");
    }

    private function pay(Order $order): Order
    {
        return (new OrderProcessService())->completedOrder($order->order_sn, (float) $order->actual_price, 'payment-123');
    }

    private function service(array $trade = [], bool $timeout = false, string $quote = '6.00', ?string $balance = '42.00'): ProcurementService
    {
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('item', ['code' => 'product'])->once()
            ->andReturn(['delivery_way' => 0, 'contact_type' => 2, 'widget' => null]);
        $client->shouldReceive('request')->with('valuation', \Mockery::on(function ($params) {
            return $params['num'] === 1 && $params['sku'] === ['版本' => '标准'];
        }))->once()->andReturn(['price' => $quote, 'currency_code' => 'CNY']);
        if (bccomp($quote, '8', 2) <= 0) {
            $expectation = $client->shouldReceive('request')->with('trade', \Mockery::on(function ($params) {
                $p = Procurement::first();
                return $p->state === 'submitting' && $p->request_no === $params['request_no']
                    && strlen($params['request_no']) <= 16 && $params['num'] === 1
                    && preg_match('/^[a-f0-9]{16}$/D', $params['password']) === 1
                    && $params['password'] !== $params['request_no']
                    && $params['shared_code'] === 'product' && !isset($params['code']);
            }))->once();
            if ($timeout) {
                $expectation->andThrow(new \RuntimeException('supplier_transport_error'));
            } else {
                $expectation->andReturn(array_merge(['tradeNo' => 'supplier-123', 'amount' => '6.00', 'secret' => 'CARD[123]'], $trade));
            }
        } else {
            $client->shouldNotReceive('request')->with('trade', \Mockery::any());
        }
        if (ProcurementFailedPush::webhookEnabled()) {
            $client->shouldReceive('request')->with('authentication/connect', [])->once()
                ->andReturn(['balance' => $balance]);
        }
        return new ProcurementService($client);
    }

    public function test_duplicate_payment_and_worker_calls_only_purchase_once()
    {
        $order = $this->order();
        $this->pay($order); $this->pay($order);
        $this->assertEquals(1, Procurement::count());
        $this->assertEquals(1, $order->goods->fresh()->sales_volume);
        $this->assertNotNull($order->fresh()->paid_at);
        $id = Procurement::first()->id;
        $service = $this->service();
        $service->process($id); $service->process($id); $service->deliver($id);
        $this->pay($order);
        $this->assertEquals('delivered', Procurement::first()->state);
        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertEquals('CARD[123]', $order->fresh()->info);
        $this->assertEquals(1, Carmis::count());
        $this->assertEquals(Carmis::first()->id, $order->fresh()->procurementOrder->carmi_id);
        $this->assertEquals('CARD[123]', $order->fresh()->procurementOrder->carmi->carmi);
        $this->assertEquals(1, ProcurementOrder::count());
        $this->assertEquals(Carmis::STATUS_SOLD, Carmis::first()->status);
        $this->assertEquals('payment-123', $order->fresh()->trade_no);
    }

    public function test_local_card_is_used_without_procurement()
    {
        $order = $this->order();
        $card = new Carmis();
        $card->forceFill(['goods_id' => $order->goods_id, 'carmi' => 'LOCAL'])->save();
        $this->pay($order); $this->pay($order);
        $this->assertEquals('LOCAL', $order->fresh()->info);
        $this->assertEquals(Carmis::STATUS_SOLD, $card->fresh()->status);
        $this->assertEquals(0, Procurement::count());
    }

    public function test_email_lookup_requires_password_and_never_treats_zero_as_empty()
    {
        $settings = Cache::get('system-setting');
        $settings['is_open_search_pwd'] = 1;
        Cache::put('system-setting', $settings);
        $protected = $this->order(['search_pwd' => 'private']);
        $zero = $this->order(['search_pwd' => '0']);
        $service = new OrderService();
        foreach (['', ' ', 'wrong'] as $password) {
            $this->assertCount(0, $service->withEmailAndPassword($protected->email, $password));
        }
        $this->assertEquals([$protected->id], $service->withEmailAndPassword($protected->email, 'private')->pluck('id')->all());
        $this->assertEquals([$zero->id], $service->withEmailAndPassword($protected->email, '0')->pluck('id')->all());
        $settings['is_open_search_pwd'] = 0;
        Cache::put('system-setting', $settings);
        $this->assertCount(2, $service->withEmailAndPassword($protected->email));
    }

    public function test_required_search_password_is_validated_even_when_omitted()
    {
        $settings = Cache::get('system-setting');
        $settings['is_open_search_pwd'] = 1;
        Cache::put('system-setting', $settings);
        foreach ([[], ['search_pwd' => ''], ['search_pwd' => ' '], ['search_pwd' => []]] as $input) {
            $validator = \Illuminate\Support\Facades\Validator::make($input, ['search_pwd' => [new \App\Rules\SearchPwd()]]);
            $this->assertTrue($validator->fails());
        }
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['search_pwd' => '0'],
            ['search_pwd' => [new \App\Rules\SearchPwd()]])->passes());
        $settings['is_open_search_pwd'] = 0;
        Cache::put('system-setting', $settings);
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make([], ['search_pwd' => [new \App\Rules\SearchPwd()]])->passes());
    }

    public function test_timeout_keeps_payment_and_never_resubmits()
    {
        $order = $this->order(); $this->pay($order);
        $service = $this->service([], true);
        $id = Procurement::first()->id;
        $service->process($id); $service->process($id); $this->pay($order); $service->recover();
        $this->assertEquals('unknown', Procurement::first()->state);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertEquals(Order::STATUS_ABNORMAL, $order->fresh()->status);
        $this->assertEquals(0, Carmis::count());
    }

    public function test_zero_price_order_purchases_once_after_payment_confirmation()
    {
        $order = $this->order(['actual_price' => '0.00']); $this->pay($order);
        $this->assertEquals(1, Procurement::count());
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);
        $service = $this->service();
        $id = Procurement::first()->id;
        $service->process($id); $service->process($id); $this->pay($order);
        $this->assertSame('delivered', Procurement::first()->state);
        $this->assertSame('CARD[123]', $order->fresh()->info);
        $this->assertEquals(1, Procurement::count());
        $this->assertEquals(1, Carmis::count());
        $this->assertEquals(1, $order->goods->fresh()->sales_volume);
    }

    public function test_quote_over_limit_does_not_trade()
    {
        $order = $this->order(); $this->pay($order);
        $this->service([], false, '8.01')->process(Procurement::first()->id);
        $this->assertEquals('failed', Procurement::first()->state);
        $this->assertEquals('quote_over_limit', Procurement::first()->error_code);
    }

    public function test_actual_cost_over_limit_delivers_and_pauses_product()
    {
        $order = $this->order(); $this->pay($order);
        $this->service(['amount' => '9.00'])->process(Procurement::first()->id);
        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertTrue(Procurement::first()->cost_exceeded);
        $this->assertEquals(1, $order->goods->fresh()->procurement_paused);
    }

    /** @dataProvider incompleteSecrets */
    public function test_empty_or_waiting_secret_is_not_delivered($secret)
    {
        $order = $this->order(); $this->pay($order);
        $this->service(['secret' => $secret])->process(Procurement::first()->id);
        $this->assertEquals('unknown', Procurement::first()->state);
        $this->assertEquals(0, Carmis::count());
        $this->assertEquals('supplier-123', Procurement::first()->supplier_order_no);
    }

    public function incompleteSecrets(): array
    {
        return [[null], [''], ['等待发货'], ['pending delivery']];
    }

    public function test_persisted_response_recovers_without_network()
    {
        $order = $this->order(); $this->pay($order);
        $purchase = Procurement::first();
        $purchase->update(['state' => 'received', 'supplier_order_no' => 'known', 'amount' => '6.00', 'secret' => 'RECOVERED']);
        $client = \Mockery::mock(IfakaClient::class); $client->shouldNotReceive('request');
        $service = new ProcurementService($client);
        $service->recover(); $service->recover();
        $this->assertEquals('RECOVERED', $order->fresh()->info);
        $this->assertEquals(1, Carmis::count());
    }

    public function test_saved_response_for_an_order_changed_elsewhere_leaves_recovery()
    {
        $order = $this->order(); $this->pay($order);
        Procurement::query()->update(['state' => 'received', 'supplier_order_no' => 'known', 'amount' => '6.00', 'secret' => 'SAVED']);
        $order->fresh()->forceFill(['status' => Order::STATUS_COMPLETED, 'info' => 'handled by support'])->save();
        $client = \Mockery::mock(IfakaClient::class); $client->shouldNotReceive('request');

        (new ProcurementService($client))->recover();

        $this->assertEquals('unknown', Procurement::first()->state);
        $this->assertEquals('order_not_processing', Procurement::first()->error_code);
        $this->assertEquals('SAVED', Procurement::first()->secret);
        $this->assertEquals('handled by support', $order->fresh()->info);
        $this->assertEquals(0, Carmis::count());
    }

    public function test_card_insert_failure_preserves_supplier_response_for_local_recovery()
    {
        $order = $this->order(); $this->pay($order);
        Carmis::creating(function () { throw new \RuntimeException('simulated_disk_failure'); });
        $service = $this->service();
        $service->process(Procurement::first()->id);
        $this->assertEquals('received', Procurement::first()->state);
        $this->assertEquals('CARD[123]', Procurement::first()->secret);
        $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);
        Carmis::flushEventListeners();
        $service->recover();
        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertEquals(1, Carmis::count());
    }

    public function test_failure_is_shown_once_without_procurement_details_on_all_themes()
    {
        $order = $this->order(['status' => Order::STATUS_ABNORMAL, 'paid_at' => now(), 'info' => 'ifaka采购失败：内部原因']);
        $settings = Cache::get('system-setting');
        $settings['customer_service_wechat'] = '<script>bad()</script>';
        Cache::put('system-setting', $settings);
        $html = view('shared.procurement-status', ['order' => $order])->render();
        $this->assertStringNotContainsString('出卡', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        foreach (['luna', 'hyper', 'unicorn'] as $theme) {
            $rendered = view($theme . '.static_pages.orderinfo', ['orders' => [$order], 'page_title' => 'Test'])->render();
            $this->assertSame(1, substr_count($rendered, '出卡失败，请联系客服'));
            $this->assertStringContainsString('class="delivery-failure-card"', $rendered);
            $this->assertStringContainsString('客服微信：', $rendered);
            $this->assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $rendered);
            $this->assertStringNotContainsString('<script>bad()', $rendered);
            $this->assertStringContainsString($theme === 'hyper' ? __('hyper.orderinfo_order_status') : __('order.fields.status'), $rendered);
            $this->assertStringNotContainsString('ifaka', $rendered);
            $this->assertStringNotContainsString('采购', $rendered);
            $this->assertStringNotContainsString('内部原因', $rendered);
            $this->assertStringNotContainsString('<textarea', $rendered);
            $this->assertStringNotContainsString('data-clipboard-text=', $rendered);
        }
    }

    public function test_luna_preserves_supplier_card_brackets_and_escapes_markup()
    {
        $order = $this->order(['status' => Order::STATUS_COMPLETED, 'paid_at' => now(), 'info' => 'CARD[123]</textarea><script>bad()</script>']);
        $html = view('luna.static_pages.orderinfo', ['orders' => [$order], 'page_title' => 'Test'])->render();
        $this->assertStringContainsString('CARD[123]&lt;/textarea&gt;', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
    }

    public function test_system_settings_encrypt_and_preserve_key_without_exposing_it()
    {
        $form = new \App\Admin\Forms\SystemSetting();
        $form->handle(['ifaka_app_id' => 'test', 'ifaka_request_prefix' => 'TEST', 'ifaka_app_key' => 'test-secret']);
        $saved = Cache::get('system-setting');
        $this->assertArrayNotHasKey('ifaka_app_key', $saved);
        $this->assertEquals('test-secret', decrypt($saved['ifaka_app_key_encrypted']));
        $form->handle(['ifaka_app_id' => 'test', 'ifaka_request_prefix' => 'TEST', 'ifaka_app_key' => '']);
        $this->assertEquals($saved['ifaka_app_key_encrypted'], Cache::get('system-setting')['ifaka_app_key_encrypted']);
        $this->assertArrayNotHasKey('ifaka_app_key_encrypted', $form->default());
    }

    public function test_paused_procurement_still_allows_local_stock_but_not_backorders()
    {
        $order = $this->order();
        $goods = $order->goods; $goods->procurementSettings->update(['procurement_paused' => true]);
        $this->assertFalse($goods->allow_backorder);
        $card = new Carmis(); $card->forceFill(['goods_id' => $goods->id, 'carmi' => 'LOCAL'])->save();
        $this->assertEquals($goods->id, (new OrderService())->validatorGoods(new Request(['gid' => $goods->id, 'by_amount' => 1]))->id);
        $this->pay($order);
        $this->assertEquals('LOCAL', $order->fresh()->info);
        $this->assertEquals(0, Procurement::count());
    }

    public function test_invalid_payment_amount_does_not_mark_paid_or_enqueue()
    {
        $order = $this->order();
        try {
            (new OrderProcessService())->completedOrder($order->order_sn, 9, 'wrong');
            $this->fail('Expected payment amount rejection');
        } catch (\App\Exceptions\RuleValidationException $exception) {
            $this->assertNull($order->fresh()->paid_at);
            $this->assertEquals(0, Procurement::count());
        }
    }

    public function test_interrupted_submission_is_unknown_and_not_retried()
    {
        $order = $this->order(); $this->pay($order);
        Procurement::query()->update(['state' => 'submitting', 'updated_at' => now()->subMinutes(6)]);
        $client = \Mockery::mock(IfakaClient::class); $client->shouldNotReceive('request');
        (new ProcurementService($client))->recover();
        $this->assertEquals('unknown', Procurement::first()->state);
    }

    public function test_worker_once_processes_pending_purchase()
    {
        $order = $this->order(); $this->pay($order);
        $this->app->instance(ProcurementService::class, $this->service());

        $this->assertEquals(0, Artisan::call('procurement:work', ['--once' => true]));

        $this->assertEquals('delivered', Procurement::first()->state);
        $this->assertEquals('CARD[123]', $order->fresh()->info);
    }

    public function test_worker_once_reports_storage_errors()
    {
        $service = \Mockery::mock(ProcurementService::class);
        $service->shouldReceive('recover')->once()->andThrow(new \RuntimeException('simulated_database_outage'));
        $this->app->instance(ProcurementService::class, $service);

        $this->expectExceptionMessage('simulated_database_outage');
        Artisan::call('procurement:work', ['--once' => true]);
    }

    public function test_zero_inventory_allows_one_but_not_two()
    {
        $order = $this->order();
        $service = new OrderService();
        $this->assertEquals($order->goods_id, $service->validatorGoods(new Request(['gid' => $order->goods_id, 'by_amount' => 1]))->id);
        $this->expectException(\App\Exceptions\RuleValidationException::class);
        $service->validatorGoods(new Request(['gid' => $order->goods_id, 'by_amount' => 2]));
    }

    public function test_stock_snapshot_is_added_only_when_fresh()
    {
        $order = $this->order(); $goods = $order->goods;
        $goods->carmis_count = 2;
        $goods->procurementSettings->supplier_stock = 5;
        $goods->procurementSettings->supplier_stock_at = now()->toDateTimeString();
        $this->assertEquals(7, $goods->in_stock);
        $this->assertEquals(7, $goods->in_stock); // Accessor must not double-count.
        $goods->procurementSettings->supplier_stock_at = now()->subMinutes(2)->toDateTimeString();
        $this->assertEquals(2, $goods->in_stock);
        $this->assertTrue($goods->stock_unknown);
    }

    public function test_paid_order_cannot_be_expired()
    {
        $order = $this->order(); $this->pay($order);
        $this->assertFalse((new OrderService())->expiredOrderSN($order->order_sn));
        $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);
    }

    public function test_status_api_distinguishes_paid_failure_from_delivery()
    {
        $order = $this->order(['status' => Order::STATUS_ABNORMAL, 'paid_at' => now()]);
        $response = $this->app->make(\App\Http\Controllers\Home\OrderController::class)->checkOrderStatus($order->order_sn)->getData(true);
        $this->assertTrue($response['paid']);
        $this->assertEquals('failed', $response['delivery']);
        $this->assertNotEquals('success', $response['msg']);
    }

    public function test_migration_preserves_legacy_schema_and_can_follow_manual_table_creation()
    {
        $this->assertEquals($this->legacySchema, $this->readLegacySchema());
        $order = $this->order();
        $this->pay($order);
        $schema = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');

        (new \AddProcurement())->up();

        $this->assertEquals($schema, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertEquals($this->legacySchema, $this->readLegacySchema());
        $this->assertEquals('pending', Procurement::first()->state);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertEquals(1, ProcurementGoods::count());
        $this->assertEquals(1, ProcurementOrder::count());
    }

    public function test_migration_rollback_does_not_touch_legacy_tables_or_records()
    {
        $order = $this->order();
        $card = new Carmis();
        $card->forceFill(['goods_id' => $order->goods_id, 'carmi' => 'EXISTING'])->save();

        (new \AddProcurement())->down();

        $this->assertEquals($this->legacySchema, $this->readLegacySchema());
        $this->assertEquals($order->order_sn, DB::table('orders')->where('id', $order->id)->value('order_sn'));
        $this->assertEquals('EXISTING', DB::table('carmis')->where('id', $card->id)->value('carmi'));
        $this->assertEquals(1, DB::table('goods')->count());
    }

    public function test_order_snapshots_mapping_and_cost_before_product_configuration_changes()
    {
        $goods = $this->order()->goods;
        $process = new OrderProcessService();
        $process->setGoods($goods);
        $process->setBuyAmount(1);
        $process->setEmail('buyer@example.invalid');
        $process->setSearchPwd('test');
        $process->setBuyIP('127.0.0.1');
        $process->setPayID(1);
        $order = $process->createOrder();
        $goods->procurementSettings->update(['supplier_code' => 'changed', 'procurement_max_cost' => '0.00']);

        $this->assertTrue($order->fresh()->procurement_enabled);
        $this->assertEquals('product', $order->fresh()->procurement_config['code']);
        $this->assertEquals('8.00', $order->fresh()->procurement_config['max_cost']);
        $this->pay($order);
        $this->service()->process(Procurement::first()->id);
        $this->assertEquals('CARD[123]', $order->fresh()->info);
        $this->assertEquals($this->legacySchema, $this->readLegacySchema());
    }

    public function test_legacy_order_without_mapping_keeps_local_delivery_and_payment_idempotency()
    {
        $order = $this->order();
        $order->procurementOrder()->delete();
        $order->goods->procurementSettings()->delete();
        $card = new Carmis();
        $card->forceFill(['goods_id' => $order->goods_id, 'carmi' => 'LEGACY'])->save();

        $this->assertFalse($order->goods->fresh()->allow_backorder);
        $this->pay($order);
        $this->pay($order);

        $this->assertEquals('LEGACY', $order->fresh()->info);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertFalse($order->fresh()->procurement_enabled);
        $this->assertEquals(1, ProcurementOrder::count());
        $this->assertEquals(0, Procurement::count());
        $this->assertEquals(1, $order->goods->fresh()->sales_volume);
    }

    public function test_order_mapping_failure_rolls_back_new_order()
    {
        $goods = $this->order()->goods;
        $process = new OrderProcessService();
        $process->setGoods($goods);
        $process->setBuyAmount(1);
        $process->setEmail('buyer@example.invalid');
        $process->setSearchPwd('test');
        $process->setBuyIP('127.0.0.1');
        $process->setPayID(1);
        ProcurementOrder::creating(function () { throw new \RuntimeException('simulated_snapshot_failure'); });

        try {
            $process->createOrder();
            $this->fail('Expected order and snapshot transaction rollback');
        } catch (\App\Exceptions\RuleValidationException $exception) {
            $this->assertEquals(1, Order::count());
            $this->assertEquals(1, ProcurementOrder::count());
            $this->assertEquals(0, Procurement::count());
            $this->assertEquals(0, DB::transactionLevel());
            Queue::assertNotPushed(\App\Jobs\OrderExpired::class);
        } finally {
            ProcurementOrder::flushEventListeners();
        }
    }

    public function test_expiration_uses_payment_mapping_and_also_handles_legacy_unpaid_orders()
    {
        $paid = $this->order(['paid_at' => now(), 'status' => Order::STATUS_WAIT_PAY]);
        $unpaid = $this->order();
        $legacy = $this->order();
        $legacy->procurementOrder()->delete();
        $service = new OrderService();

        $this->assertFalse($service->expiredOrderSN($paid->order_sn));
        $this->assertTrue($service->expiredOrderSN($unpaid->order_sn));
        $this->assertTrue($service->expiredOrderSN($legacy->order_sn));
        $this->assertEquals(Order::STATUS_WAIT_PAY, $paid->fresh()->status);
        $this->assertEquals(Order::STATUS_EXPIRED, $legacy->fresh()->status);
    }

    public function test_enqueue_failure_rolls_back_payment_mapping_and_order_changes()
    {
        $order = $this->order();
        Procurement::creating(function () { throw new \RuntimeException('simulated_queue_storage_failure'); });
        try {
            $this->pay($order);
            $this->fail('Expected payment transaction rollback');
        } catch (\App\Exceptions\RuleValidationException $exception) {
            $this->assertNull($order->fresh()->paid_at);
            $this->assertEquals(Order::STATUS_WAIT_PAY, $order->fresh()->status);
            $this->assertEquals(0, $order->goods->fresh()->sales_volume);
            $this->assertEquals(0, Procurement::count());
        } finally {
            Procurement::flushEventListeners();
        }
    }

    public function test_card_binding_failure_rolls_back_card_insert_and_recovers_locally()
    {
        $order = $this->order();
        $this->pay($order);
        ProcurementOrder::saving(function ($mapping) {
            if ($mapping->carmi_id !== null) { throw new \RuntimeException('simulated_binding_failure'); }
        });
        $service = $this->service();
        try {
            $service->process(Procurement::first()->id);
            $this->assertEquals('received', Procurement::first()->state);
            $this->assertEquals(0, Carmis::count());
            $this->assertNull($order->fresh()->procurementOrder->carmi_id);
            $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);
        } finally {
            ProcurementOrder::flushEventListeners();
        }
        $service->recover();
        $service->recover();
        $this->assertEquals('delivered', Procurement::first()->state);
        $this->assertEquals(1, Carmis::count());
        $this->assertEquals(Carmis::first()->id, $order->fresh()->procurementOrder->carmi_id);
    }

    public function test_stock_command_reads_and_writes_product_mapping_table()
    {
        $goods = $this->order()->goods;
        $legacy = new Goods();
        $legacy->save();
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('stock', [
            'code' => 'product', 'race' => '', 'sku' => ['版本' => '标准'],
        ])->once()->andReturn(['stock' => 7]);
        $this->app->instance(IfakaClient::class, $client);

        $this->assertEquals(0, Artisan::call('procurement:stock'));
        $this->assertEquals(7, $goods->fresh()->procurementSettings->supplier_stock);
        $this->assertNotNull($goods->fresh()->procurementSettings->supplier_stock_at);
        $this->assertNull($legacy->fresh()->procurementSettings);
    }

    public function test_stock_response_cannot_overwrite_a_changed_product_mapping()
    {
        $goods = $this->order()->goods;
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->once()->andReturnUsing(function () use ($goods) {
            ProcurementGoods::where('goods_id', $goods->id)->update(['supplier_code' => 'changed']);
            return ['stock' => 7];
        });
        (new ProcurementService($client))->refreshStock($goods);

        $this->assertEquals('changed', $goods->fresh()->procurementSettings->supplier_code);
        $this->assertNull($goods->fresh()->procurementSettings->supplier_stock);
    }

    public function test_each_home_visit_uses_one_list_request_for_all_three_mapped_products()
    {
        $first = $this->order()->goods;
        $second = $this->order()->goods;
        $third = $this->order()->goods;
        $first->procurementSettings->update(['supplier_code' => 'plus', 'supplier_sku' => '{}']);
        $second->procurementSettings->update(['supplier_code' => 'go', 'supplier_sku' => '{}']);
        $third->procurementSettings->update(['supplier_code' => 'pro', 'supplier_sku' => '{}']);
        $card = new Carmis();
        $card->forceFill(['goods_id' => $first->id, 'carmi' => 'LOCAL'])->save();
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('items', [])->twice()->andReturn(
            [['children' => [['code' => 'plus', 'stock' => '1873'], ['code' => 'go', 'stock' => 619],
                ['code' => 'pro', 'stock' => 617], ['code' => 'unmapped', 'stock' => 999]]]],
            [['children' => [['code' => 'plus', 'stock' => '1872'], ['code' => 'go', 'stock' => 618],
                ['code' => 'pro', 'stock' => 616]]]]
        );
        $this->app->instance(IfakaClient::class, $client);
        $service = new \App\Service\GoodsService();
        $groups = $service->withGroup();
        $again = $service->withGroup();
        $goods = collect($groups[0]['goods'])->keyBy('id');
        $this->assertEquals(1874, $goods[$first->id]['in_stock']);
        $this->assertEquals(619, $goods[$second->id]['in_stock']);
        $this->assertEquals(617, $goods[$third->id]['in_stock']);
        $this->assertTrue($goods[$first->id]['allow_backorder']);
        $this->assertFalse($goods[$first->id]['stock_unknown']);
        $this->assertCount(3, ProcurementGoods::all());
        $nextGoods = collect($again[0]['goods'])->keyBy('id');
        $this->assertEquals(1873, $nextGoods[$first->id]['in_stock']);
        $this->assertEquals(618, $nextGoods[$second->id]['in_stock']);
        $this->assertEquals(616, $nextGoods[$third->id]['in_stock']);
        $this->assertArrayNotHasKey('procurement_settings', $goods[$first->id]);
        $this->assertEquals(0, Procurement::count());
        $this->assertEquals(1, (new OrderService())->validatorGoods(new Request(['gid' => $first->id, 'by_amount' => 1]))->id);
    }

    public function test_list_zero_missing_invalid_and_duplicate_product_inventory_remain_distinct()
    {
        $all = [];
        foreach (['zero', 'missing', 'invalid', 'duplicate'] as $code) {
            $goods = $this->order()->goods;
            $goods->procurementSettings->update(['supplier_code' => $code, 'supplier_sku' => '{}', 'supplier_stock' => 9]);
            $all[$code] = $goods;
        }
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('items', [])->once()->andReturn([
            ['children' => [['code' => 'zero', 'stock' => 0], ['code' => 'invalid', 'stock' => -1],
                ['code' => 'duplicate', 'stock' => 5], ['code' => 'duplicate', 'stock' => 6]]],
        ]);
        (new ProcurementService($client))->refreshHomeStock();
        $this->assertSame(0, $all['zero']->fresh()->procurementSettings->supplier_stock);
        $this->assertFalse($all['zero']->fresh()->stock_unknown);
        foreach (['missing', 'invalid', 'duplicate'] as $code) {
            $this->assertNull($all[$code]->fresh()->procurementSettings->supplier_stock);
            $this->assertTrue($all[$code]->fresh()->stock_unknown);
            $this->assertTrue($all[$code]->fresh()->allow_backorder);
        }
    }

    public function test_list_outage_does_not_break_home_and_the_next_visit_can_recover()
    {
        $goods = $this->order()->goods;
        $goods->procurementSettings->update(['supplier_sku' => '{}', 'supplier_stock' => 9]);
        $client = \Mockery::mock(IfakaClient::class);
        $attempt = 0;
        $client->shouldReceive('request')->with('items', [])->twice()->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 1) { throw new \RuntimeException('supplier_transport_error'); }
            return [['children' => [['code' => 'product', 'stock' => 7]]]];
        });
        $this->app->instance(IfakaClient::class, $client);
        $service = new \App\Service\GoodsService();
        $groups = $service->withGroup();
        $this->assertSame(0, $groups[0]['goods'][0]['in_stock']);
        $this->assertTrue($groups[0]['goods'][0]['stock_unknown']);
        $this->assertTrue($groups[0]['goods'][0]['allow_backorder']);
        $this->assertNull($goods->fresh()->procurementSettings->supplier_stock);
        $next = $service->withGroup();
        $this->assertSame(7, $next[0]['goods'][0]['in_stock']);
        $this->assertFalse($next[0]['goods'][0]['stock_unknown']);
    }

    public function test_list_refresh_cannot_overwrite_a_new_mapping()
    {
        $goods = $this->order()->goods;
        $goods->procurementSettings->update(['supplier_sku' => '{}']);
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('items', [])->once()->andReturnUsing(function () use ($goods) {
            ProcurementGoods::where('goods_id', $goods->id)->update(['supplier_code' => 'changed']);
            return [['children' => [['code' => 'product', 'stock' => 7]]]];
        });
        (new ProcurementService($client))->refreshHomeStock();
        $this->assertSame('changed', $goods->fresh()->procurementSettings->supplier_code);
        $this->assertNull($goods->fresh()->procurementSettings->supplier_stock);
    }

    public function test_manual_inventory_command_uses_list_for_simple_goods_and_stock_for_variants()
    {
        $simple = $this->order()->goods;
        $simple->procurementSettings->update(['supplier_code' => 'simple', 'supplier_sku' => '{}']);
        $variant = $this->order()->goods;
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('items', [])->once()->andReturn([
            ['children' => [['code' => 'simple', 'stock' => 30], ['code' => 'product', 'stock' => 500]]],
        ]);
        $client->shouldReceive('request')->with('stock', ['code' => 'product', 'race' => '', 'sku' => ['版本' => '标准']])
            ->once()->andReturn(['stock' => 2]);
        $this->app->instance(IfakaClient::class, $client);
        $this->assertEquals(0, Artisan::call('procurement:stock'));
        $this->assertSame(30, $simple->fresh()->procurementSettings->supplier_stock);
        $this->assertSame(2, $variant->fresh()->procurementSettings->supplier_stock);
    }

    public function test_simultaneous_home_refresh_does_not_call_supplier_again()
    {
        $goods = $this->order()->goods;
        $goods->procurementSettings->update(['supplier_sku' => '{}']);
        $lock = Cache::lock('procurement:home-stock:' . hash('sha256', 'test') . ':lock', 30);
        $this->assertTrue($lock->get());
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldNotReceive('request');
        (new ProcurementService($client))->refreshHomeStock();
        $this->assertNull($goods->fresh()->procurementSettings->supplier_stock_at);
        $lock->release();
    }

    public function test_scheduler_does_not_refresh_supplier_inventory()
    {
        $goods = $this->order()->goods;
        $goods->procurementSettings->update(['supplier_sku' => '{}']);
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldNotReceive('request');
        $this->app->instance(IfakaClient::class, $client);
        $this->assertEquals(0, Artisan::call('schedule:run'));
        $this->assertNull($goods->fresh()->procurementSettings->supplier_stock_at);
    }

    private function enableFailurePush(): string
    {
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=test-bot-key';
        Cache::put('system-setting', array_merge(Cache::get('system-setting'), ['procurement_failure_wecom_url' => $url]));
        return $url;
    }

    public function test_procurement_failure_without_webhook_does_not_queue_notification()
    {
        $order = $this->order(); $this->pay($order);
        $this->service([], true)->process(Procurement::first()->id);
        Queue::assertNotPushed(ProcurementFailedPush::class);
    }

    public function test_paid_procurement_timeout_queues_one_detailed_failure_notification()
    {
        $this->enableFailurePush();
        $order = $this->order(); $this->pay($order);
        $id = Procurement::first()->id;
        $service = $this->service([], true);
        $service->process($id); $service->process($id); $service->recover(); $this->pay($order);
        Queue::assertPushed(ProcurementFailedPush::class, 1);
        Queue::assertPushed(ProcurementFailedPush::class, function ($job) use ($order) {
            $this->assertStringContainsString($order->order_sn, $job->message);
            $this->assertStringContainsString('supplier_transport_error', $job->message);
            $this->assertStringContainsString('异常时间：', $job->message);
            $this->assertStringContainsString((string) $order->created_at, $job->message);
            $this->assertStringContainsString('付款确认时间：', $job->message);
            $this->assertStringContainsString('采购请求号：TEST', $job->message);
            $this->assertStringContainsString('可能已经扣款', $job->message);
            $this->assertStringContainsString('商品单价（下单时）：10.00 元', $job->message);
            $this->assertStringContainsString('失败后ifaka账户余额：42.00', $job->message);
            $this->assertStringNotContainsString('时区：', $job->message);
            $this->assertStringNotContainsString($order->email, $job->message);
            $this->assertStringNotContainsString('test-bot-key', serialize($job));
            return true;
        });
    }

    public function test_zero_price_procurement_failure_still_notifies_once()
    {
        $this->enableFailurePush();
        $order = $this->order(['actual_price' => '0.00']);
        $this->pay($order); $this->pay($order);
        $this->assertEquals(1, Procurement::count());
        $service = $this->service([], true);
        $service->process(Procurement::first()->id);
        $service->process(Procurement::first()->id);
        Queue::assertPushed(ProcurementFailedPush::class, 1);
        Queue::assertPushed(ProcurementFailedPush::class, function ($job) {
            return strpos($job->message, 'supplier_transport_error') !== false
                && strpos($job->message, '实付金额：0') !== false;
        });
    }

    public function test_zero_price_order_keeps_the_procurement_cost_limit()
    {
        $order = $this->order(['actual_price' => '0.00']); $this->pay($order);
        $this->service([], false, '8.01')->process(Procurement::first()->id);
        $this->assertSame('failed', Procurement::first()->state);
        $this->assertSame('quote_over_limit', Procurement::first()->error_code);
    }

    public function test_pending_delivery_is_shown_once_and_polls_without_showing_internal_info()
    {
        $order = $this->order(['status' => Order::STATUS_PROCESSING, 'paid_at' => now(), 'info' => '内部采购处理中']);
        foreach (['luna', 'hyper', 'unicorn'] as $theme) {
            $html = view($theme . '.static_pages.orderinfo', ['orders' => [$order], 'page_title' => 'Test'])->render();
            $visible = strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html));
            $this->assertSame(1, substr_count($visible, '正在出卡'));
            $this->assertStringContainsString('采购中', $visible);
            $this->assertStringContainsString($theme === 'hyper' ? __('hyper.orderinfo_order_status') : __('order.fields.status'), $visible);
            $this->assertStringContainsString('class="delivery-wait-card"', $html);
            $this->assertSame(1, substr_count($html, 'class="delivery-countdown-value">10</span>'));
            $this->assertStringContainsString('delivery-countdown-progress', $html);
            $this->assertStringContainsString('正在出卡，请稍后', $html);
            $this->assertStringContainsString('clearInterval(countdownTimer)', $html);
            $this->assertStringContainsString('class="delivery-pending"', $html);
            $this->assertStringNotContainsString('内部采购处理中', $html);
            $this->assertStringNotContainsString('<textarea', $html);
        }
    }

    public function test_unpaid_and_successful_orders_do_not_send_failure_notifications()
    {
        $this->enableFailurePush();
        $order = $this->order();
        ProcurementFailedPush::notify($order, 'internal_error');
        $this->pay($order);
        $this->service()->process(Procurement::first()->id);
        Queue::assertPushed(ProcurementFailedPush::class, function ($job) {
            return strpos($job->message, '【ifaka采购成功／订单已出卡】') !== false;
        });
        Queue::assertNotPushed(ProcurementFailedPush::class, function ($job) {
            return strpos($job->message, '【ifaka采购/出卡异常】') !== false
                || strpos($job->message, '【订单出卡异常】') !== false;
        });
    }

    public function test_successful_purchase_notification_includes_post_purchase_balance()
    {
        $this->enableFailurePush();
        $order = $this->order(); $this->pay($order);
        $this->service()->process(Procurement::first()->id);
        Queue::assertPushed(ProcurementFailedPush::class, 1);
        Queue::assertPushed(ProcurementFailedPush::class, function ($job) use ($order) {
            $this->assertStringContainsString('【ifaka采购成功／订单已出卡】', $job->message);
            $this->assertStringContainsString($order->order_sn, $job->message);
            $this->assertStringContainsString('ifaka采购金额：6.00', $job->message);
            $this->assertStringContainsString('ifaka采购订单号：supplier-123', $job->message);
            $this->assertStringContainsString('商品单价（下单时）：10.00 元', $job->message);
            $this->assertStringContainsString('卡密：CARD[123]', $job->message);
            $this->assertStringContainsString('采购后ifaka账户余额：42.00', $job->message);
            $this->assertStringContainsString('余额查询时间：', $job->message);
            $this->assertStringNotContainsString('时区：', $job->message);
            return true;
        });
    }

    public function test_failure_notification_says_when_balance_query_failed()
    {
        $this->enableFailurePush();
        $order = $this->order(); $this->pay($order);
        $this->service([], true, '6.00', null)->process(Procurement::first()->id);
        Queue::assertPushed(ProcurementFailedPush::class, function ($job) {
            return strpos($job->message, '失败后ifaka账户余额：暂时无法查询') !== false;
        });
    }

    public function test_supplier_balance_endpoint_returns_the_post_purchase_account_balance()
    {
        Cache::put('system-setting', array_merge(Cache::get('system-setting'), [
            'ifaka_app_key_encrypted' => encrypt('private-app-key'),
        ]));
        $client = new class extends IfakaClient {
            protected function transport(string $endpoint, string $body): string
            {
                $this->assertEndpoint($endpoint);
                parse_str($body, $parameters);
                if (array_keys($parameters) !== ['app_id', 'sign'] || !$parameters['app_id'] || !$parameters['sign']) {
                    throw new \RuntimeException('Balance request must only contain signed account credentials');
                }
                return '{"code":200,"msg":"success","data":{"shopName":"test","balance":"123.45"}}';
            }

            private function assertEndpoint(string $endpoint): void
            {
                if ($endpoint !== 'authentication/connect') { throw new \RuntimeException('Unexpected endpoint'); }
            }
        };
        $this->assertEquals('123.45', $client->request('authentication/connect', [])['balance']);
    }

    public function test_account_balance_endpoint_uses_its_distinct_documented_path()
    {
        $client = new class extends IfakaClient {
            public function urlForTest(string $endpoint): string { return $this->endpointUrl($endpoint); }
        };
        $this->assertSame('https://ifaka.app/shared/authentication/connect', $client->urlForTest('authentication/connect'));
        $this->assertSame('https://ifaka.app/shared/commodity/item', $client->urlForTest('item'));
    }

    public function test_notification_enqueue_failure_preserves_the_terminal_procurement_state()
    {
        $this->enableFailurePush();
        $order = $this->order(); $this->pay($order);
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $this->service([], true)->process(Procurement::first()->id);
        $this->assertSame('unknown', Procurement::first()->state);
        $this->assertEquals(Order::STATUS_ABNORMAL, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_failure_push_validates_configuration_and_can_be_disabled()
    {
        $url = $this->enableFailurePush();
        $form = new \App\Admin\Forms\SystemSetting();
        $input = Cache::get('system-setting');
        $input['procurement_failure_wecom_url'] = 'http://127.0.0.1/private';
        $form->handle($input);
        $this->assertSame($url, Cache::get('system-setting')['procurement_failure_wecom_url']);
        $input['procurement_failure_wecom_url'] = '';
        $form->handle($input);
        $this->assertSame('', Cache::get('system-setting')['procurement_failure_wecom_url']);
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldNotReceive('post');
        (new ProcurementFailedPush('Previously queued notification'))->handle($client);
    }

    public function test_failure_push_sends_the_expected_text_and_checks_wecom_response()
    {
        $url = $this->enableFailurePush();
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldReceive('post')->with($url, \Mockery::on(function ($options) {
            return $options['json'] === ['msgtype' => 'text', 'text' => ['content' => '订单异常，余额不足']]
                && $options['verify'] === true && $options['allow_redirects'] === false
                && $options['timeout'] === 8;
        }))->once()->andReturn(new \GuzzleHttp\Psr7\Response(200, [], '{"errcode":0,"errmsg":"ok"}'));
        (new ProcurementFailedPush('订单异常，余额不足'))->handle($client);
        $this->assertTrue(true);
    }

    public function test_failure_push_rejects_wecom_business_errors()
    {
        $this->enableFailurePush();
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldReceive('post')->once()->andReturn(new \GuzzleHttp\Psr7\Response(200, [], '{"errcode":93000}'));
        $this->expectExceptionMessage('procurement_failure_notification_rejected');
        (new ProcurementFailedPush('failure'))->handle($client);
    }

    public function test_failure_push_transport_errors_do_not_expose_the_webhook_key()
    {
        $url = $this->enableFailurePush();
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldReceive('post')->once()->andThrow(new \RuntimeException('Request to ' . $url . ' failed'));
        try {
            (new ProcurementFailedPush('failure'))->handle($client);
            $this->fail('Expected sanitized transport exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('procurement_failure_notification_transport_error', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('test-bot-key', (string) $exception);
        }
    }

    public function test_supplier_rejection_keeps_the_reason_without_secrets()
    {
        Cache::put('system-setting', array_merge(Cache::get('system-setting'), ['ifaka_app_key_encrypted' => encrypt('private-app-key')]));
        $client = new class extends IfakaClient {
            protected function transport(string $endpoint, string $body): string
            {
                parse_str($body, $parameters);
                return json_encode(['code' => 500, 'msg' => '余额不足 private-app-key ' . $parameters['sign']
                    . ' ' . $parameters['password'] . ' ' . $parameters['contact'], 'data' => ['secret' => 'DO-NOT-LEAK']]);
            }
        };
        try {
            $client->request('trade', ['password' => 'private-password', 'contact' => 'buyer@example.invalid']);
            $this->fail('Expected supplier rejection');
        } catch (\App\Exceptions\SupplierRejectedException $exception) {
            $this->assertStringContainsString('supplier_rejected:余额不足', $exception->getMessage());
            foreach (['private-app-key', 'private-password', 'buyer@example.invalid', 'DO-NOT-LEAK'] as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
            $this->assertLessThanOrEqual(100, mb_strlen($exception->getMessage()));
        }
    }

    public function test_supplier_rejection_is_preserved_and_notified_without_repurchase()
    {
        $this->enableFailurePush();
        $order = $this->order(); $this->pay($order);
        $client = \Mockery::mock(IfakaClient::class);
        $client->shouldReceive('request')->with('item', \Mockery::any())->once()->andReturn(['delivery_way' => 0, 'contact_type' => 2]);
        $client->shouldReceive('request')->with('valuation', \Mockery::any())->once()->andReturn(['price' => '6.00', 'currency_code' => 'CNY']);
        $client->shouldReceive('request')->with('trade', \Mockery::any())->once()
            ->andThrow(new \App\Exceptions\SupplierRejectedException('supplier_rejected:余额不足'));
        $service = new ProcurementService($client);
        $id = Procurement::first()->id;
        $service->process($id); $service->process($id);
        $this->assertSame('supplier_rejected:余额不足', Procurement::first()->error_code);
        $this->assertSame('unknown', Procurement::first()->state);
        Queue::assertPushed(ProcurementFailedPush::class, 1);
        Queue::assertPushed(ProcurementFailedPush::class, function ($job) {
            return strpos($job->message, '供应商拒绝请求：余额不足') !== false;
        });
    }

    public function test_order_created_time_has_an_unbroken_label_and_complete_time_element()
    {
        $order = $this->order(['created_at' => '2026-10-29 20:38:12']);
        $html = view('luna.static_pages.orderinfo', ['orders' => [$order], 'page_title' => 'Test'])->render();
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//li[@class="order-created"]/strong')->length);
        $this->assertSame(1, $xpath->query('//li[@class="order-created"]/time')->length);
        $this->assertSame('2026-10-29 20:38:12', $xpath->query('//li[@class="order-created"]/time')->item(0)->textContent);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    public function test_purchase_pages_no_longer_show_the_procurement_explanation()
    {
        $order = $this->order();
        $service = new \App\Service\GoodsService();
        $goods = $service->format($service->detail($order->goods_id));
        $goods->payways = []; $goods->open_coupon = 0;
        foreach (['luna', 'hyper', 'unicorn'] as $theme) {
            $html = view($theme . '.static_pages.buy', $goods->toArray() + ['page_title' => 'Test'])->render();
            $this->assertStringNotContainsString('库存不足时将尝试采购', $html);
            $this->assertStringNotContainsString('第三方库存暂未确认', $html);
            $this->assertStringContainsString('create-order', $html);
        }
    }

    public function test_completed_delivery_shows_the_card_instead_of_the_countdown()
    {
        $order = $this->order(['status' => Order::STATUS_COMPLETED, 'paid_at' => now(), 'info' => 'CARD-OK']);
        foreach (['luna', 'hyper', 'unicorn'] as $theme) {
            $html = view($theme . '.static_pages.orderinfo', ['orders' => [$order], 'page_title' => 'Test'])->render();
            $this->assertStringContainsString('CARD-OK', $html);
            $this->assertStringNotContainsString('class="delivery-wait-card"', $html);
            $this->assertStringContainsString($theme === 'hyper' ? __('hyper.orderinfo_order_status') : __('order.fields.status'), $html);
            $this->assertStringContainsString('data-clipboard-text="CARD-OK"', $html);
            $this->assertStringNotContainsString('class="delivery-pending"', $html);
        }
    }

    public function test_public_arrays_keep_delivery_flags_and_hide_supplier_mapping()
    {
        $order = $this->order(['paid_at' => now()]);
        $goodsData = $order->goods->toArray();
        $orderData = $order->toArray();
        $this->assertTrue($goodsData['procurement_enabled']);
        $this->assertTrue($goodsData['allow_backorder']);
        $this->assertArrayNotHasKey('procurement_settings', $goodsData);
        $this->assertTrue($orderData['procurement_enabled']);
        $this->assertNotNull($orderData['paid_at']);
        $this->assertArrayNotHasKey('procurement_order', $orderData);
        $this->assertArrayNotHasKey('procurement_config', $orderData);
    }

    private function goodsForm(): \Dcat\Admin\Form
    {
        $this->app['request']->headers->set('X-Requested-With', 'XMLHttpRequest');
        $controller = new class extends \App\Admin\Controllers\GoodsController {
            public function formForTest() { return $this->form(); }
        };
        return $controller->formForTest();
    }

    private function goodsFormInput(): array
    {
        Cache::put('system-setting', Cache::get('system-setting') + ['ifaka_app_key_encrypted' => encrypt('test-secret')]);
        return [
            'gd_name' => 'Admin product', 'gd_description' => 'Test', 'gd_keywords' => 'Test',
            'group_id' => 1, 'type' => 1, 'actual_price' => '10.00', 'retail_price' => '10.00',
            'in_stock' => 0, 'is_open' => 1, 'buy_limit_num' => 0,
            'procurement_settings' => [
                'procurement_enabled' => 1, 'procurement_paused' => 0,
                'supplier_code' => 'admin-product', 'supplier_race' => '',
                'supplier_sku' => '{}', 'procurement_max_cost' => '8.00',
            ],
        ];
    }

    public function test_admin_form_creates_and_updates_mapping_without_legacy_columns()
    {
        $input = $this->goodsFormInput();
        $response = $this->goodsForm()->store($input, false);
        $this->assertEquals(1, ProcurementGoods::count(), $response->getContent());
        $goods = Goods::first();
        $this->assertTrue($goods->procurement_enabled);
        $this->assertEquals(1, $goods->buy_limit_num);
        $this->assertEquals('admin-product', $goods->procurementSettings->supplier_code);
        $form = $this->goodsForm()->edit($goods->id);
        $this->assertEquals('admin-product', $form->model()->toArray()['procurement_settings']['supplier_code']);

        $goods->procurementSettings->update(['supplier_stock' => 9, 'supplier_stock_at' => now()]);
        $input['procurement_settings']['supplier_code'] = 'updated-product';
        $response = $this->goodsForm()->update($goods->id, $input, false);
        $settings = $goods->fresh()->procurementSettings;
        $this->assertEquals('updated-product', $settings->supplier_code, $response->getContent());
        $this->assertNull($settings->supplier_stock);
        $this->assertNull($settings->supplier_stock_at);
        $this->assertEquals(1, ProcurementGoods::count());
        $this->assertEquals($this->legacySchema, $this->readLegacySchema());
    }

    public function test_admin_form_rejects_invalid_mapping_before_creating_product()
    {
        $input = $this->goodsFormInput();
        $input['procurement_settings']['supplier_sku'] = '[]';
        $response = $this->goodsForm()->store($input, false);
        $this->assertStringContainsString('SKU', $response->getContent());
        $this->assertEquals(0, Goods::count());
        $this->assertEquals(0, ProcurementGoods::count());
    }

    public function test_admin_form_rejects_zero_cost_limit_when_procurement_is_enabled()
    {
        $input = $this->goodsFormInput();
        $input['procurement_settings']['procurement_max_cost'] = '0.00';
        $response = $this->goodsForm()->store($input, false);
        $this->assertStringContainsString('大于 0', json_decode($response->getContent(), true)['data']['message'] ?? '');
        $this->assertEquals(0, Goods::count());
        $this->assertEquals(0, ProcurementGoods::count());
    }

    public function test_admin_can_add_mapping_to_an_existing_product()
    {
        $goods = new Goods();
        $goods->save();
        $this->assertNull($goods->fresh()->procurementSettings);

        $response = $this->goodsForm()->update($goods->id, $this->goodsFormInput(), false);

        $this->assertEquals(1, Goods::count(), $response->getContent());
        $this->assertEquals(1, ProcurementGoods::count());
        $this->assertTrue($goods->fresh()->procurement_enabled);
        $this->assertEquals('admin-product', $goods->fresh()->procurementSettings->supplier_code);
        $this->assertSame('8.00', $goods->fresh()->procurementSettings->procurement_max_cost);
        $this->assertEquals(1, $goods->fresh()->buy_limit_num);
        $this->assertEquals($this->legacySchema, $this->readLegacySchema());
    }

    public function test_admin_mapping_failure_rolls_back_new_product()
    {
        $input = $this->goodsFormInput();
        ProcurementGoods::creating(function () { throw new \RuntimeException('simulated_mapping_failure'); });

        try {
            $this->goodsForm()->store($input, false);
            $this->fail('Expected product and mapping transaction rollback');
        } catch (\RuntimeException $exception) {
            $this->assertEquals('simulated_mapping_failure', $exception->getMessage());
            $this->assertEquals(0, Goods::count());
            $this->assertEquals(0, ProcurementGoods::count());
            $this->assertEquals(0, DB::transactionLevel());
        } finally {
            ProcurementGoods::flushEventListeners();
        }
    }
}
