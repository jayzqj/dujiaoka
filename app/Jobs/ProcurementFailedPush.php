<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Procurement;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcurementFailedPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 15;
    public $retryAfter = 30;
    public $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public static function validWebhook($url): bool
    {
        return is_string($url) && preg_match('#\Ahttps://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=[A-Za-z0-9_-]{1,200}\z#', $url) === 1;
    }

    public static function webhookEnabled(): bool
    {
        return self::validWebhook(dujiaoka_config_get('procurement_failure_wecom_url', ''));
    }

    // Call only after committing the first transition to an exceptional state.
    public static function notify(Order $order, string $reason, ?Procurement $purchase = null, ?string $balance = null): void
    {
        if (!$order->paid_at || !self::webhookEnabled()) {
            return;
        }
        $reasons = [
            'order_not_eligible' => '订单不满足采购条件',
            'procurement_paused' => '商品采购已暂停或商品不可用',
            'invalid_product_mapping' => '供应商商品映射或成本上限无效',
            'supplier_account_changed' => '供应商账号与下单时不一致',
            'invalid_request_prefix' => '采购请求号前缀无效',
            'request_number_exhausted' => '采购请求号超出允许长度',
            'unsupported_supplier_product' => '供应商商品的交付方式、控件或购买条件不受支持',
            'invalid_quote_or_currency' => '供应商报价格式或币种异常',
            'quote_over_limit' => '供应商报价超过订单采购成本上限',
            'procurement_paused_or_claim_expired' => '采购已暂停或任务处理状态已改变',
            'supplier_not_configured' => '供应商凭据未配置',
            'supplier_transport_error' => '供应商请求超时、连接失败或 HTTP 状态异常',
            'supplier_invalid_or_rejected_response' => '供应商响应格式异常或请求被拒绝',
            'delivery_not_confirmed' => '未收到完整可交付卡密或成交信息',
            'local_delivery_failed' => '供应商响应已保存，但本站卡密入库失败',
            'worker_interrupted' => '采购进程中断，任务超过处理期限',
            'order_not_processing' => '供应商响应已保存，但订单状态已被修改',
            'local_stock_insufficient' => '本地卡密不足且未能进入采购',
            'internal_error' => '本站采购处理异常，请核对采购记录',
        ];
        $description = $reasons[$reason] ?? '采购处理异常，请核对原因代码';
        if (strpos($reason, 'supplier_rejected:') === 0) {
            $description = '供应商拒绝请求：' . substr($reason, strlen('supplier_rejected:'));
        }
        $title = preg_replace('/[\r\n\x00-\x1F]+/u', ' ', (string) $order->title);
        $lines = [
            $purchase ? '【ifaka 采购/出卡异常】' : '【订单出卡异常】',
            '订单号：' . $order->order_sn,
            '商品单价（下单时）：' . number_format((float) $order->goods_price, 2, '.', '') . ' 元',
            '卡密：' . preg_replace('/[\r\n\x00-\x1F]+/u', ' ', trim((string) $order->info)),
            '商品：' . mb_substr($title, 0, 120),
            '实付金额：' . $order->actual_price . ' 元',
            '失败原因：' . $description,
            '原因代码：' . $reason,
            '订单创建时间：' . $order->created_at,
            '付款确认时间：' . $order->paid_at,
            '异常时间：' . ($purchase ? $purchase->updated_at : $order->updated_at),
            '失败后ifaka账户余额：' . ($balance !== null ? $balance : '暂时无法查询'),
            '余额查询时间：' . now(),
        ];
        if ($purchase) {
            $lines[] = '采购状态：' . $purchase->state;
            $lines[] = '采购请求号：' . ($purchase->request_no ?: '尚未提交');
            $lines[] = '供应商订单号：' . ($purchase->supplier_order_no ?: '未知');
            if ($purchase->state === 'unknown') {
                $lines[] = '结果未知，可能已经扣款，请先核对供应商记录，不要重复采购。';
            }
        }
        try {
            app(Dispatcher::class)->dispatch(new self(mb_strcut(implode("\n", $lines), 0, 2000, 'UTF-8')));
        } catch (Throwable $exception) {
            // Notification failures must never change payment or procurement state, or leak the hook URL.
            Log::warning('procurement_failure_notification_enqueue_failed', ['order_id' => $order->id]);
        }
    }

    public static function notifyPurchaseSucceeded(Order $order, Procurement $purchase, ?string $balance): void
    {
        if (!$order->paid_at || !self::webhookEnabled()) { return; }
        $title = preg_replace('/[\\r\\n\\x00-\\x1F]+/u', ' ', (string) $order->title);
        $message = implode("\n", [
            '【ifaka采购成功／订单已出卡】',
            '订单号：' . $order->order_sn,
            '商品单价（下单时）：' . number_format((float) $order->goods_price, 2, '.', '') . ' 元',
            '卡密：' . preg_replace('/[\r\n\x00-\x1F]+/u', ' ', trim((string) $order->info)),
            '商品：' . mb_substr($title, 0, 120),
            '实付金额：' . $order->actual_price,
            'ifaka采购金额：' . number_format((float) $purchase->amount, 2, '.', ''),
            'ifaka采购订单号：' . ($purchase->supplier_order_no ?: '未知'),
            '采购后ifaka账户余额：' . ($balance !== null ? $balance : '暂时无法查询'),
            '余额查询时间：' . now(),
            '订单创建时间：' . $order->created_at,
            '付款确认时间：' . $order->paid_at,
            '出卡完成时间：' . ($purchase->updated_at ?: $order->updated_at),
        ]);
        try {
            app(Dispatcher::class)->dispatch(new self(mb_strcut($message, 0, 2000, 'UTF-8')));
        } catch (Throwable $exception) {
            Log::warning('procurement_success_notification_enqueue_failed', ['order_id' => $order->id]);
        }
    }

    public function handle(Client $client): void
    {
        $url = dujiaoka_config_get('procurement_failure_wecom_url', '');
        if (!self::validWebhook($url)) { return; }
        try {
            $response = $client->post($url, [
                'json' => ['msgtype' => 'text', 'text' => ['content' => $this->message]],
                'connect_timeout' => 3, 'timeout' => 8, 'verify' => true,
                'allow_redirects' => false, 'http_errors' => false,
            ]);
        } catch (Throwable $exception) {
            // Guzzle exceptions include URLs; never retain the original exception containing the bot key.
            throw new RuntimeException('procurement_failure_notification_transport_error');
        }
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($data) || !array_key_exists('errcode', $data)
            || (string) $data['errcode'] !== '0') {
            throw new RuntimeException('procurement_failure_notification_rejected');
        }
    }
}
