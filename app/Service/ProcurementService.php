<?php

namespace App\Service;

use App\Exceptions\SupplierRejectedException;
use App\Jobs\ProcurementFailedPush;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Procurement;
use App\Models\ProcurementGoods;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcurementService
{
    private $client;

    public function __construct(IfakaClient $client)
    {
        $this->client = $client;
    }

    // Called in the payment transaction. The database row is the durable work queue.
    public function enqueue(Order $order): void
    {
        $order->status = Order::STATUS_PROCESSING;
        $order->info = '正在出卡';
        $order->save();
        Procurement::create(['order_id' => $order->id, 'state' => 'pending']);
    }

    public function process(int $id): void
    {
        // Conditional claim works across workers; no HTTP request is made under a database lock.
        if (!Procurement::where('id', $id)->where('state', 'pending')
            ->update(['state' => 'checking', 'updated_at' => now()])) {
            return;
        }
        $submitted = false;
        try {
            $purchase = Procurement::findOrFail($id);
            $order = $purchase->order;
            if (!$order || !$order->paid_at || (int) $order->status !== Order::STATUS_PROCESSING
                || (int) $order->buy_amount !== 1 || bccomp($order->actual_price, '0', 2) < 0) {
                throw new RuntimeException('order_not_eligible');
            }
            $goods = $order->goods;
            if (!$goods || $goods->procurement_paused) {
                throw new RuntimeException('procurement_paused');
            }
            $config = $order->procurement_config;
            if (!is_array($config) || empty($config['code']) || !IfakaClient::money($config['max_cost'] ?? null)) {
                throw new RuntimeException('invalid_product_mapping');
            }
            if (($config['app_id'] ?? '') !== (string) dujiaoka_config_get('ifaka_app_id', '')) {
                throw new RuntimeException('supplier_account_changed');
            }
            $prefix = strtoupper((string) dujiaoka_config_get('ifaka_request_prefix', ''));
            if (!preg_match('/^[A-Z0-9]{2,4}$/D', $prefix)) {
                throw new RuntimeException('invalid_request_prefix');
            }
            $requestNo = $prefix . strtoupper(str_pad(base_convert((string) $id, 10, 36), 12, '0', STR_PAD_LEFT));
            if (strlen($requestNo) > 16) {
                throw new RuntimeException('request_number_exhausted');
            }
            $parameters = ['code' => $config['code'], 'race' => $config['race'] ?? '', 'sku' => $config['sku'] ?? []];
            $item = $this->client->request('item', ['code' => $config['code']]);
            $widget = $item['widget'] ?? null;
            if (is_string($widget) && trim($widget) !== '') {
                $widget = json_decode($widget, true) ?? $widget;
            }
            if (!array_key_exists('delivery_way', $item) || (string) $item['delivery_way'] !== '0'
                || !empty($widget) || (int) ($item['minimum'] ?? 0) > 1
                || !in_array((int) ($item['contact_type'] ?? 0), [0, 2], true)) {
                throw new RuntimeException('unsupported_supplier_product');
            }
            $quote = $this->client->request('valuation', $parameters + ['num' => 1, 'card_id' => 0]);
            if (!IfakaClient::money($quote['price'] ?? null) || ($quote['currency_code'] ?? '') !== 'CNY') {
                throw new RuntimeException('invalid_quote_or_currency');
            }
            if (bccomp($quote['price'], $config['max_cost'], 2) > 0) {
                throw new RuntimeException('quote_over_limit');
            }

            // Persist the no-retry barrier BEFORE the request which may debit the account.
            $claimed = DB::transaction(function () use ($id, $requestNo, $goods) {
                $currentGoods = Goods::where('id', $goods->id)->lockForUpdate()->first();
                $settings = ProcurementGoods::where('goods_id', $goods->id)->lockForUpdate()->first();
                if (!$currentGoods || !$settings || $settings->procurement_paused) {
                    return false;
                }
                return Procurement::where('id', $id)->where('state', 'checking')->update([
                    'state' => 'submitting', 'request_no' => $requestNo,
                    'submitted_at' => now(), 'updated_at' => now(),
                ]);
            });
            if (!$claimed) {
                throw new RuntimeException('procurement_paused_or_claim_expired');
            }
            $submitted = true;
            unset($parameters['code']);
            $response = $this->client->request('trade', $parameters + [
                'shared_code' => $config['code'], 'num' => 1, 'request_no' => $requestNo,
                'card_id' => 0, 'contact' => $order->email,
                'password' => bin2hex(random_bytes(8)), 'coupon' => '', 'device' => 0,
            ]);
            $tradeNo = $response['tradeNo'] ?? null;
            $amount = $response['amount'] ?? null;
            $secret = $response['secret'] ?? null;
            $validTrade = is_string($tradeNo) && $tradeNo !== '' && strlen($tradeNo) <= 190;
            $validAmount = IfakaClient::money($amount);
            $overLimit = $validAmount && bccomp($amount, $config['max_cost'], 2) > 0;
            // Persist the response separately so a later card insert failure can recover locally.
            DB::transaction(function () use ($id, $order, $validTrade, $tradeNo, $validAmount, $amount, $secret, $overLimit) {
                if ($overLimit) {
                    ProcurementGoods::where('goods_id', $order->goods_id)->update(['procurement_paused' => true]);
                }
                Procurement::where('id', $id)->where('state', 'submitting')->update([
                    'state' => 'received', 'supplier_order_no' => $validTrade ? $tradeNo : null,
                    'amount' => $validAmount ? $amount : null,
                    'secret' => is_string($secret) ? $secret : null,
                    'cost_exceeded' => $overLimit, 'updated_at' => now(),
                ]);
            });
            $this->deliver($id);
        } catch (Throwable $exception) {
            // A saved response can be delivered again locally, but a purchase is NEVER resubmitted.
            if (Procurement::where('id', $id)->value('state') === 'received') {
                Log::warning('procurement_delivery_deferred', ['procurement_id' => $id]);
                return;
            }
            $reason = $exception instanceof RuntimeException ? $exception->getMessage() : 'internal_error';
            $allowed = ['order_not_eligible', 'procurement_paused', 'invalid_product_mapping',
                'supplier_account_changed', 'invalid_request_prefix', 'request_number_exhausted',
                'unsupported_supplier_product', 'invalid_quote_or_currency', 'quote_over_limit',
                'procurement_paused_or_claim_expired', 'supplier_not_configured',
                'supplier_transport_error', 'supplier_invalid_or_rejected_response'];
            $this->fail($id, $submitted ? 'unknown' : 'failed',
                $exception instanceof SupplierRejectedException || in_array($reason, $allowed, true) ? $reason : 'internal_error');
        }
    }

    public function deliver(int $id): void
    {
        $purchase = Procurement::findOrFail($id);
        if ($purchase->state !== 'received') {
            return;
        }
        if (!$purchase->supplier_order_no || $purchase->amount === null || !IfakaClient::deliverableSecret($purchase->secret)) {
            $this->fail($id, 'unknown', 'delivery_not_confirmed');
            return;
        }
        $manualReview = null;
        $deliveredOrder = DB::transaction(function () use ($purchase, &$manualReview) {
            $order = Order::where('id', $purchase->order_id)->lockForUpdate()->first();
            $current = Procurement::where('id', $purchase->id)->lockForUpdate()->first();
            if ($current->state !== 'received') {
                return null;
            }
            if (!$order || (int) $order->status !== Order::STATUS_PROCESSING) {
                // The order was changed elsewhere; keep the response for manual review instead of retrying forever.
                $current->state = 'unknown';
                $current->error_code = 'order_not_processing';
                $current->save();
                if ($order) { $manualReview = [$order, $current]; }
                return null;
            }
            $mapping = $order->procurementOrder()->lockForUpdate()->firstOrFail();
            if ($mapping->carmi_id !== null) {
                throw new RuntimeException('card_already_bound');
            }
            $card = new Carmis();
            $card->goods_id = $order->goods_id;
            $card->status = Carmis::STATUS_SOLD;
            $card->is_loop = 0;
            $card->carmi = $current->secret;
            $card->save();
            $mapping->carmi_id = $card->id;
            $mapping->save();
            $order->info = $current->secret;
            $order->status = Order::STATUS_COMPLETED;
            $order->save();
            $current->state = 'delivered';
            $current->save();
            return $order;
        });
        if ($manualReview) {
            ProcurementFailedPush::notify($manualReview[0], 'order_not_processing', $manualReview[1]);
        }
        if ($deliveredOrder) {
            app('Service\OrderProcessService')->notifyCompletedOrder($deliveredOrder);
        }
    }

    public function fail(int $id, string $state, string $reason, ?string $staleState = null): void
    {
        $purchase = Procurement::find($id);
        if (!$purchase) {
            return;
        }
        $failure = DB::transaction(function () use ($purchase, $state, $reason, $staleState) {
            $order = Order::where('id', $purchase->order_id)->lockForUpdate()->first();
            $current = Procurement::where('id', $purchase->id)->lockForUpdate()->first();
            if (!$current || in_array($current->state, ['delivered', 'failed', 'unknown'], true)) {
                return;
            }
            if ($staleState !== null && ($current->state !== $staleState || $current->updated_at >= now()->subMinutes(5))) {
                return;
            }
            $current->state = $state;
            $current->error_code = $reason;
            $current->save();
            if ($order && (int) $order->status === Order::STATUS_PROCESSING) {
                $order->status = Order::STATUS_ABNORMAL;
                $order->info = '出卡异常';
                $order->save();
            }
            return $order ? [$order, $current] : null;
        });
        if ($failure) {
            ProcurementFailedPush::notify($failure[0], $reason, $failure[1]);
        }
    }

    public function recover(): void
    {
        foreach (Procurement::where('state', 'received')->limit(100)->pluck('id') as $id) {
            try { $this->deliver($id); } catch (Throwable $e) {
                Log::warning('procurement_delivery_deferred', ['procurement_id' => $id]);
                $this->fail($id, 'unknown', 'local_delivery_failed', 'received');
            }
        }
        foreach (Procurement::whereIn('state', ['checking', 'submitting'])
            ->where('updated_at', '<', now()->subMinutes(5))->limit(100)->get() as $purchase) {
            $this->fail($purchase->id, $purchase->state === 'submitting' ? 'unknown' : 'failed', 'worker_interrupted', $purchase->state);
        }
    }

    private function usesCatalogStock(Goods $goods): bool
    {
        $settings = $goods->procurementSettings;
        return $settings && (string) $settings->supplier_race === ''
            && empty(json_decode($settings->supplier_sku ?: '{}', true));
    }

    public function refreshHomeStock(): void
    {
        $goods = Goods::whereHas('procurementSettings', function ($query) {
            $query->where('procurement_enabled', true);
        })->where('is_open', Goods::STATUS_OPEN)->where('type', Goods::AUTOMATIC_DELIVERY)
            ->get();
        if ($goods->isEmpty()) {
            return;
        }
        $key = 'procurement:home-stock:' . hash('sha256', (string) dujiaoka_config_get('ifaka_app_id', ''));
        // Only coalesce in-flight refreshes. The next visit after this one refreshes again.
        $lock = Cache::lock($key . ':lock', max(30, $goods->count() * 5));
        if (!$lock->get()) {
            return;
        }
        try {
            $catalogGoods = $goods->filter(function ($item) { return $this->usesCatalogStock($item); });
            if ($catalogGoods->isNotEmpty()) {
                $stocks = [];
                try {
                    // One list response supplies every simple product, regardless of the mapping count.
                    $stocks = $this->catalogStocks($this->client->request('items', []));
                } catch (Throwable $exception) {
                    // Unknown stock does not prevent opening the storefront; the next visit retries.
                }
                foreach ($catalogGoods as $item) {
                    $settings = $item->procurementSettings;
                    $this->saveStockSnapshot($settings, $stocks[$settings->supplier_code] ?? null);
                }
            }
            foreach ($goods as $item) {
                if (!$this->usesCatalogStock($item)) { $this->refreshStock($item); }
            }
        } finally {
            $lock->release();
        }
    }

    private function catalogStocks(array $catalog): array
    {
        $stocks = [];
        $walk = function (array $nodes) use (&$walk, &$stocks) {
            foreach ($nodes as $node) {
                if (!is_array($node)) { continue; }
                if (isset($node['code']) && is_string($node['code'])) {
                    $stock = $node['stock'] ?? null;
                    $valid = (is_int($stock) || is_string($stock)) && preg_match('/^\d{1,9}$/D', (string) $stock);
                    $stocks[$node['code']] = array_key_exists($node['code'], $stocks)
                        ? null : ($valid ? (int) $stock : null);
                }
                if (isset($node['children']) && is_array($node['children'])) { $walk($node['children']); }
            }
        };
        $walk($catalog);
        return $stocks;
    }

    public function refreshStock(Goods $goods): void
    {
        $settings = $goods->procurementSettings;
        if (!$settings) {
            return;
        }
        $stock = null;
        try {
            $data = $this->client->request('stock', [
                'code' => $settings->supplier_code, 'race' => $settings->supplier_race ?? '',
                'sku' => json_decode($settings->supplier_sku ?: '{}', true) ?: [],
            ]);
            $value = $data['stock'] ?? null;
            if ((is_int($value) || is_string($value)) && preg_match('/^\d{1,9}$/D', (string) $value)) {
                $stock = (int) $value;
            }
        } catch (Throwable $exception) {
            // Unknown inventory does not prohibit purchase, per the agreed sales policy.
        }
        $this->saveStockSnapshot($settings, $stock);
    }

    private function saveStockSnapshot(ProcurementGoods $settings, ?int $stock): void
    {
        // A response for an old mapping must not become the stock of a newly selected SKU.
        ProcurementGoods::where('id', $settings->id)
            ->where('supplier_code', $settings->supplier_code)
            ->where('supplier_race', $settings->supplier_race)
            ->where('supplier_sku', $settings->supplier_sku)
            ->update(['supplier_stock' => $stock, 'supplier_stock_at' => now()]);
    }
}
