<?php

namespace Tests\Support;

use App\Service\IfakaClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// This client never contacts the supplier. A database log also works across PHP processes.
class ProcurementMysqlClient extends IfakaClient
{
    public function request(string $endpoint, array $parameters): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Supplier request made inside a database transaction');
        }
        DB::table('procurement_test_calls')->insert(['endpoint' => $endpoint]);
        switch ($endpoint) {
            case 'item':
                return ['delivery_way' => 0, 'contact_type' => 2, 'widget' => null];
            case 'valuation':
                return ['price' => '6.00', 'currency_code' => 'CNY'];
            case 'trade':
                return ['tradeNo' => 'supplier-' . $parameters['request_no'],
                    'amount' => '6.00', 'secret' => 'MYSQL[' . $parameters['request_no'] . ']'];
            default:
                throw new RuntimeException('Unexpected test supplier endpoint');
        }
    }
}
