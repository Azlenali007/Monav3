<?php
/**
 * Mona SMM Panel v2 - PhonePe Payment Gateway Adapter (India)
 * Official API: PhonePe PG Standard Checkout v1
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class PhonePeGateway extends AbstractGateway {
    public function getCode(): string {
        return 'phonepe';
    }

    public function getName(): string {
        return 'PhonePe Payment Gateway';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            'salt_key' => ['label' => 'Salt Key', 'type' => 'password', 'required' => true],
            'salt_index' => ['label' => 'Salt Index', 'type' => 'text', 'required' => true, 'default' => '1']
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('merchant_id')) && !empty($this->getCredential('salt_key'));
    }

    private function getBaseUrl(): string {
        return $this->isSandbox()
            ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
            : 'https://api.phonepe.com/apis/hermes';
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'PhonePe credentials not configured'];
        }

        $merchantId = $this->getCredential('merchant_id');
        $saltKey = $this->getCredential('salt_key');
        $saltIndex = $this->getCredential('salt_index', '1');
        $txId = $payment['transaction_id'];

        $amountInPaise = (int)round((float)$payment['amount'] * 100);

        $requestData = [
            'merchantId' => $merchantId,
            'merchantTransactionId' => $txId,
            'merchantUserId' => 'MUID_' . $user['id'],
            'amount' => $amountInPaise,
            'redirectUrl' => SITE_URL . "/user/payment-status.php?tx={$txId}",
            'redirectMode' => 'POST',
            'callbackUrl' => SITE_URL . "/api/payments.php?gateway=phonepe",
            'mobileNumber' => '9999999999',
            'paymentInstrument' => [
                'type' => 'PAY_PAGE'
            ]
        ];

        $base64Payload = base64_encode(json_encode($requestData));
        $endpoint = '/pg/v1/pay';
        $checksum = hash('sha256', $base64Payload . $endpoint . $saltKey) . '###' . $saltIndex;

        $headers = [
            'Content-Type: application/json',
            'X-VERIFY: ' . $checksum
        ];

        $payload = [
            'request' => $base64Payload
        ];

        $res = $this->makeRequest($this->getBaseUrl() . $endpoint, 'POST', $payload, $headers);

        if (!$res['success'] || empty($res['data']['data']['instrumentResponse']['redirectInfo']['url'])) {
            $msg = $res['data']['message'] ?? $res['error'] ?? 'PhonePe pay page generation failed';
            return ['status' => 'error', 'error' => 'PhonePe API error: ' . $msg];
        }

        $redirectUrl = $res['data']['data']['instrumentResponse']['redirectInfo']['url'];

        return [
            'status' => 'redirect',
            'redirect_url' => $redirectUrl,
            'gateway_ref' => $txId
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $saltKey = $this->getCredential('salt_key');
        $saltIndex = $this->getCredential('salt_index', '1');

        if (empty($saltKey)) {
            return ['verified' => false, 'error' => 'PhonePe salt key not configured'];
        }

        $xVerify = $headers['x-verify'] ?? $headers['X-VERIFY'] ?? null;
        $responseBody = $postData['response'] ?? null;

        // If JSON payload delivered directly in body
        if (!$responseBody) {
            $json = json_decode($rawPayload, true);
            $responseBody = $json['response'] ?? null;
        }

        if (empty($responseBody) || empty($xVerify)) {
            return ['verified' => false, 'error' => 'Missing PhonePe verification parameters'];
        }

        $expectedChecksum = hash('sha256', $responseBody . $saltKey) . '###' . $saltIndex;
        if (!hash_equals($expectedChecksum, $xVerify)) {
            return ['verified' => false, 'error' => 'Invalid PhonePe X-VERIFY signature'];
        }

        $decodedJson = json_decode(base64_decode($responseBody), true);
        if (!is_array($decodedJson) || empty($decodedJson['code'])) {
            return ['verified' => false, 'error' => 'Invalid base64 payload in PhonePe response'];
        }

        $code = $decodedJson['code'];
        $data = $decodedJson['data'] ?? [];
        $txId = $data['merchantTransactionId'] ?? null;
        $phonePeTxId = $data['transactionId'] ?? null;
        $amount = isset($data['amount']) ? ((float)$data['amount'] / 100) : 0.0;

        if ($code === 'PAYMENT_SUCCESS') {
            return [
                'verified' => true,
                'event_id' => $phonePeTxId ?: $txId,
                'transaction_id' => $txId,
                'gateway_ref' => $phonePeTxId,
                'amount' => $amount,
                'currency' => 'INR',
                'status' => 'completed'
            ];
        }

        return [
            'verified' => true,
            'event_id' => $phonePeTxId ?: $txId,
            'transaction_id' => $txId,
            'status' => 'failed',
            'error' => "PhonePe returned status: {$code}"
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $merchantId = $this->getCredential('merchant_id');
        $saltKey = $this->getCredential('salt_key');
        $saltIndex = $this->getCredential('salt_index', '1');
        $txId = $transactionId ?? $gatewayRef;

        $endpoint = "/pg/v1/status/{$merchantId}/{$txId}";
        $checksum = hash('sha256', $endpoint . $saltKey) . '###' . $saltIndex;

        $headers = [
            'Content-Type: application/json',
            'X-VERIFY: ' . $checksum,
            'X-MERCHANT-ID: ' . $merchantId
        ];

        $res = $this->makeRequest($this->getBaseUrl() . $endpoint, 'GET', null, $headers);

        if ($res['success'] && ($res['data']['code'] ?? '') === 'PAYMENT_SUCCESS') {
            $data = $res['data']['data'] ?? [];
            return [
                'status' => 'completed',
                'gateway_ref' => $data['transactionId'] ?? $txId,
                'amount' => (float)($data['amount'] ?? 0) / 100,
                'currency' => 'INR'
            ];
        }

        return ['status' => 'pending'];
    }
}
