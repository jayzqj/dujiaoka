<?php

namespace App\Service;

use App\Exceptions\SupplierRejectedException;
use RuntimeException;

class IfakaClient
{
    public static function sign(array $parameters, string $key): string
    {
        unset($parameters['sign']);
        ksort($parameters);
        $parameters = array_filter($parameters, function ($value) { return $value !== ''; });
        return md5(urldecode(http_build_query($parameters) . '&key=' . $key));
    }

    public function request(string $endpoint, array $parameters): array
    {
        $settings = cache('system-setting') ?: [];
        $appId = $settings['ifaka_app_id'] ?? '';
        $encryptedKey = $settings['ifaka_app_key_encrypted'] ?? '';
        if (!$appId || !$encryptedKey) {
            throw new RuntimeException('supplier_not_configured');
        }
        $parameters['app_id'] = $appId;
        $key = decrypt($encryptedKey);
        $parameters['sign'] = self::sign($parameters, $key);
        $response = $this->transport($endpoint, http_build_query($parameters));
        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['code']) && (string) $decoded['code'] !== '200') {
            $message = $decoded['msg'] ?? $decoded['message'] ?? '供应商未提供错误描述';
            $message = is_string($message) ? strip_tags($message) : '供应商未提供错误描述';
            foreach ([$key, $parameters['sign'], $parameters['password'] ?? '', $parameters['contact'] ?? ''] as $secret) {
                if (is_string($secret) && $secret !== '') { $message = str_replace($secret, '[已隐藏]', $message); }
            }
            $message = preg_replace('#https?://\S+#i', '[链接已隐藏]', $message);
            $message = preg_replace('/[\r\n\x00-\x1F]+/u', ' ', $message);
            throw new SupplierRejectedException('supplier_rejected:' . mb_substr($message, 0, 70));
        }
        if (!is_array($decoded) || (string) ($decoded['code'] ?? '') !== '200' || !is_array($decoded['data'] ?? null)) {
            // Never log supplier responses: they may contain card secrets or signed parameters.
            throw new RuntimeException('supplier_invalid_or_rejected_response');
        }
        return $decoded['data'];
    }

    protected function transport(string $endpoint, string $body): string
    {
        if (!in_array($endpoint, ['items', 'item', 'stock', 'valuation', 'trade', 'query', 'authentication/connect'], true)) {
            throw new RuntimeException('unsupported_supplier_endpoint');
        }
        $curl = curl_init($this->endpointUrl($endpoint));
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => in_array($endpoint, ['items', 'item', 'stock', 'authentication/connect'], true) ? 2 : 5,
            CURLOPT_TIMEOUT => in_array($endpoint, ['items', 'item', 'stock', 'authentication/connect'], true) ? 4 : 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response === false || $status !== 200) {
            throw new RuntimeException('supplier_transport_error');
        }
        return $response;
    }

    protected function endpointUrl(string $endpoint): string
    {
        return $endpoint === 'authentication/connect'
            ? 'https://ifaka.app/shared/authentication/connect'
            : 'https://ifaka.app/shared/commodity/' . $endpoint;
    }

    public static function money($value): bool
    {
        return (is_string($value) || is_numeric($value))
            && preg_match('/^\d{1,10}(\.\d{1,2})?$/D', (string) $value) === 1;
    }

    public static function deliverableSecret($value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }
        // This is a conservative waiting-message guard, not a card format validator.
        return !preg_match('/等待|待发货|待处理|审核中|处理中|稍后|pending|awaiting|processing/i', $value);
    }
}
