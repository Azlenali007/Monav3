<?php
/**
 * Mona SMM Panel v2 - Cashfree Payments Adapter (India)
 * Official API: Cashfree PG Orders API v2023-08-01
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class CashfreeGateway extends AbstractGateway {
    public function getCode(): string {
        return 'cashfree';
    }

    public function getName(): string {
        return 'Cashfree Payments';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'app_id' => ['label' => 'App ID / Client ID', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('app_id')) && !empty($this->getCredential('secret_key'));
    }

    private function getBaseUrl(): string {
        return $this->isSandbox() ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Cashfree credentials not configured'];
        }

        $appId = $this->getCredential('app_id');
        $secretKey = $this->getCredential('secret_key');
        $orderId = 'cf_' . $payment['transaction_id'];

        $headers = [
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secretKey,
            'x-api-version: 2023-08-01'
        ];

        $payload = [
            'order_id' => $orderId,
            'order_amount' => round((float)$payment['amount'], 2),
            'order_currency' => strtoupper($payment['currency'] ?? 'INR'),
            'customer_details' => [
                'customer_id' => (string)$user['id'],
                'customer_name' => $user['username'],
                'customer_email' => $user['email'],
                'customer_phone' => '9999999999'
            ],
            'order_meta' => [
                'return_url' => SITE_URL . "/user/payment-status.php?tx={$payment['transaction_id']}&order_id={order_id}",
                'notify_url' => SITE_URL . "/api/payments.php?gateway=cashfree"
            ],
            'order_note' => "Deposit for {$user['username']}"
        ];

        $res = $this->makeRequest($this->getBaseUrl() . '/orders', 'POST', $payload, $headers);

        if (!$res['success'] || empty($res['data']['payment_session_id'])) {
            $err = $res['data']['message'] ?? $res['error'] ?? 'Cashfree order creation failed';
            return ['status' => 'error', 'error' => 'Cashfree API error: ' . $err];
        }

        $sessionId = $res['data']['payment_session_id'];

        return [
            'status' => 'custom',
            'gateway_ref' => $orderId,
            'custom_data' => [
                'payment_session_id' => $sessionId,
                'order_id' => $orderId,
                'environment' => $this->isSandbox() ? 'sandbox' : 'production'
            ]
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $secretKey = $this->getCredential('secret_key');
        if (empty($secretKey)) {
            return ['verified' => false, 'error' => 'Cashfree secret key not configured'];
        }

        $timestamp = $headers['x-webhook-timestamp'] ?? $headers['X-Webhook-Timestamp'] ?? null;
        $signature = $headers['x-webhook-signature'] ?? $headers['X-Webhook-Signature'] ?? null;

        if (empty($timestamp) || empty($signature)) {
            return ['verified' => false, 'error' => 'Missing Cashfree webhook signature headers'];
        }

        // Cashfree signature: base64(hmac-sha256(timestamp + rawPayload, secretKey))
        $dataToSign = $timestamp . $rawPayload;
        $computedSignature = base64_encode(hash_hmac('sha256', $dataToSign, $secretKey, true));

        if (!hash_equals($computedSignature, $signature)) {
            return ['verified' => false, 'error' => 'Invalid Cashfree webhook signature'];
        }

        $event = json_decode($rawPayload, true);
        if (!is_array($event) || empty($event['type'])) {
            return ['verified' => false, 'error' => 'Invalid JSON webhook payload'];
        }

        if ($event['type'] !== 'PAYMENT_SUCCESS_WEBHOOK') {
            return [
                'verified' => true,
                'event_id' => $event['event_time'] ?? null,
                'status' => 'pending',
                'error' => "Ignored event type: {$event['type']}"
            ];
        }

        $orderData = $event['data']['order'] ?? [];
        $paymentData = $event['data']['payment'] ?? [];
        $orderId = $orderData['order_id'] ?? '';
        $transactionId = str_starts_with($orderId, 'cf_') ? substr($orderId, 3) : $orderId;
        $amount = (float)($orderData['order_amount'] ?? $paymentData['payment_amount'] ?? 0);
        $currency = strtoupper($orderData['order_currency'] ?? 'INR');
        $cfPaymentId = (string)($paymentData['cf_payment_id'] ?? '');

        return [
            'verified' => true,
            'event_id' => $cfPaymentId ?: ($event['event_time'] ?? null),
            'transaction_id' => $transactionId,
            'gateway_ref' => $cfPaymentId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $orderId = str_starts_with($gatewayRef, 'cf_') ? $gatewayRef : ('cf_' . ($transactionId ?? $gatewayRef));
        $headers = [
            'x-client-id: ' . $this->getCredential('app_id'),
            'x-client-secret: ' . $this->getCredential('secret_key'),
            'x-api-version: 2023-08-01'
        ];

        $res = $this->makeRequest($this->getBaseUrl() . "/orders/{$orderId}", 'GET', null, $headers);

        if (!$res['success'] || empty($res['data']['order_status'])) {
            return ['status' => 'error', 'error' => 'Failed to query Cashfree order'];
        }

        if ($res['data']['order_status'] === 'PAID') {
            return [
                'status' => 'completed',
                'gateway_ref' => $orderId,
                'amount' => (float)$res['data']['order_amount'],
                'currency' => strtoupper($res['data']['order_currency'])
            ];
        }

        return ['status' => 'pending'];
    }
}
