<?php

// Invoked only by ProcurementMysqlTest, with an isolated database configuration on stdin.
require __DIR__ . '/../../vendor/autoload.php';

use App\Service\IfakaClient;
use App\Service\OrderProcessService;
use App\Service\ProcurementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcurementMysqlClient;

$input = json_decode(stream_get_contents(STDIN), true);
if (!is_array($input) || !preg_match('/^dujiaoka_procurement_test_[a-f0-9]{16}$/D', $input['connection']['database'] ?? '')
    || !in_array($input['connection']['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "An isolated local test database is required.\n");
    exit(1);
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'procurement_mysql',
    'database.connections.procurement_mysql' => $input['connection'],
    'cache.default' => 'array', 'app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);
Cache::put('system-setting', ['ifaka_app_id' => 'mysql-test', 'ifaka_request_prefix' => 'MT']);
Queue::fake();
$app->instance(IfakaClient::class, new ProcurementMysqlClient());

try {
    fwrite(STDOUT, "ready\n");
    if ($input['action'] === 'pay') {
        (new OrderProcessService())->completedOrder($input['order_sn'], 10, 'mysql-payment');
    } elseif ($input['action'] === 'process') {
        $app->make(ProcurementService::class)->process($input['id']);
    } elseif ($input['action'] === 'recover') {
        $app->make(ProcurementService::class)->recover();
    } else {
        throw new RuntimeException('Unknown test action');
    }
    fwrite(STDOUT, "done\n");
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
