<?php
/**
 * Mona SMM Panel v2 - Cryptomus Gateway Adapter (Cryptocurrency)
 * Official API: Cryptomus v1 Payment API & MD5-Base64 Signature Verification
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class CryptomusGateway extends AbstractGateway {
    public function getCode(): string {
        return 'cryptomus';
    }

    public function getName(): string {
        return 'Cryptomus';
    }

    public function getType(): string {
        return 'crypto';
    }

    public function getConfigFields(): array {
        return [
            'merchant_uuid' => ['label' => 'Merchant UUID', 'type' => 'text', 'required' => true],
            'api_key' => ['label' => 'Payment API Key', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('merchant_uuid')) && !empty($this->getCredential('api_key'));
    }

    private function generateSignature(array $data): string {
        $apiKey = $this->getCredential('api_key');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return md5(base64_encode($json) . $apiKey);
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Cryptomus credentials not configured'];
        }

        $merchantUuid = $this->getCredential('merchant_uuid');
        $txId = $payment['transaction_id'];

        $payload = [
            'amount' => number_format((float)$payment['amount'], 2, '.', ''),
            'currency' => strtoupper($payment['currency'] ?? 'USD'),
            'order_id' => $txId,
            'url_return' => SITE_URL . "/user/payment-status.php?tx={$txId}",
            'url_callback' => SITE_URL . "/api/payments.php?gateway=cryptomus",
            'is_payment_multiple' => false,
            'lifetime' => 3600,
            'additional_data' => "user_{$user['id']}"
        ];

        $sign = $this->generateSignature($payload);

        $headers = [
            'merchant: ' . $merchantUuid,
            'sign: ' . $sign,
            'Content-Type: application/json'
        ];

        $res = $this->makeRequest('https://api.cryptomus.com/v1/payment', 'POST', $payload, $headers);

        if (!$res['success'] || empty($res['data']['result']['url'])) {
            $msg = $res['data']['message'] ?? $res['error'] ?? 'Cryptomus invoice creation failed';
            return ['status' => 'error', 'error' => 'Cryptomus API error: ' . $msg];
        }

        $result = $res['data']['result'];

        return [
            'status' => 'redirect',
            'redirect_url' => $result['url'],
            'gateway_ref' => $result['uuid'] ?? $txId
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $apiKey = $this->getCredential('api_key');
        if (empty($apiKey)) {
            return ['verified' => false, 'error' => 'Cryptomus API key not configured'];
        }

        $data = json_decode($rawPayload, true);
        if (!is_array($data) || empty($data['sign'])) {
            return ['verified' => false, 'error' => 'Missing Cryptomus signature in webhook body'];
        }

        $receivedSign = $data['sign'];
        unset($data['sign']);

        // Official verification: md5(base64_encode(json_encode($data)) + apiKey)
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expectedSign = md5(base64_encode($json) . $apiKey);

        if (!hash_equals($expectedSign, $receivedSign)) {
            return ['verified' => false, 'error' => 'Cryptomus signature verification mismatch'];
        }

        $status = strtolower($data['status'] ?? '');
        $txId = $data['order_id'] ?? null;
        $uuid = $data['uuid'] ?? null;
        $amount = (float)($data['merchant_amount'] ?? $data['amount'] ?? 0);
        $currency = strtoupper($data['currency'] ?? 'USD');

        // Statuses 'paid' or 'paid_over' signify successfully completed payments
        if (in_array($status, ['paid', 'paid_over'])) {
            return [
                'verified' => true,
                'event_id' => $uuid ?: $txId,
                'transaction_id' => $txId,
                'gateway_ref' => $uuid,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed'
            ];
        }

        if (in_array($status, ['cancel', 'fail', 'system_fail'])) {
            return [
                'verified' => true,
                'event_id' => $uuid ?: $txId,
                'transaction_id' => $txId,
                'status' => 'failed',
                'error' => "Cryptomus status: {$status}"
            ];
        }

        return [
            'verified' => true,
            'event_id' => $uuid ?: $txId,
            'transaction_id' => $txId,
            'status' => 'pending'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $merchantUuid = $this->getCredential('merchant_uuid');
        $payload = ['uuid' => $gatewayRef];
        if ($transactionId) {
            $payload['order_id'] = $transactionId;
        }

        $headers = [
            'merchant: ' . $merchantUuid,
            'sign: ' . $this->generateSignature($payload),
            'Content-Type: application/json'
        ];

        $res = $this->makeRequest('https://api.cryptomus.com/v1/payment/info', 'POST', $payload, $headers);

        if ($res['success'] && isset($res['data']['result']['status'])) {
            $status = strtolower($res['data']['result']['status']);
            if (in_array($status, ['paid', 'paid_over'])) {
                return [
                    'status' => 'completed',
                    'gateway_ref' => $res['data']['result']['uuid'],
                    'amount' => (float)$res['data']['result']['amount'],
                    'currency' => strtoupper($res['data']['result']['currency'])
                ];
            }
        }

        return ['status' => 'pending'];
    }
}
