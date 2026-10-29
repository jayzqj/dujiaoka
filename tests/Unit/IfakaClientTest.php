<?php

namespace Tests\Unit;

use App\Service\IfakaClient;
use PHPUnit\Framework\TestCase;

class IfakaClientTest extends TestCase
{
    public function test_signature_preserves_zero_and_decodes_nested_form_values()
    {
        $params = ['sku' => ['版本' => '标准 +'], 'race' => '', 'num' => 1, 'card_id' => 0, 'app_id' => 'demo', 'sign' => 'ignored'];
        $expected = md5('app_id=demo&card_id=0&num=1&sku[版本]=标准 +&key=secret');
        $this->assertEquals($expected, IfakaClient::sign($params, 'secret'));
    }

    public function test_invalid_money_values_are_rejected()
    {
        foreach ([-1, 'NaN', '1e3', '', [], '1.001'] as $value) {
            $this->assertFalse(IfakaClient::money($value));
        }
        $this->assertTrue(IfakaClient::money('0.00'));
        $this->assertTrue(IfakaClient::money('12.34'));
    }
}
