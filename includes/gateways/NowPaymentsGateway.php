<?php
/**
 * Mona SMM Panel v2 - NOWPayments Gateway Adapter (Cryptocurrency)
 * Official API: NOWPayments v1 Invoice API & HMAC-SHA512 IPN Verification
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class NowPaymentsGateway extends AbstractGateway {
    public function getCode(): string {
        return 'nowpayments';
    }

    public function getName(): string {
        return 'NOWPayments';
    }

    public function getType(): string {
        return 'crypto';
    }

    public function getConfigFields(): array {
        return [
            'api_key' => ['label' => 'API Key', 'type' => 'text', 'required' => true],
            'ipn_secret' => ['label' => 'IPN Secret Key', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('api_key')) && !empty($this->getCredential('ipn_secret'));
    }

    private function getBaseUrl(): string {
        return $this->isSandbox() ? 'https://api-sandbox.nowpayments.io/v1' : 'https://api.nowpayments.io/v1';
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'NOWPayments credentials not configured'];
        }

        $apiKey = $this->getCredential('api_key');
        $txId = $payment['transaction_id'];

        $headers = [
            'x-api-key: ' . $apiKey,
            'Content-Type: application/json'
        ];

        $payload = [
            'price_amount' => round((float)$payment['amount'], 2),
            'price_currency' => strtolower($payment['currency'] ?? 'usd'),
            'order_id' => $txId,
            'order_description' => "Deposit for {$user['username']}",
            'ipn_callback_url' => SITE_URL . "/api/payments.php?gateway=nowpayments",
            'success_url' => SITE_URL . "/user/payment-status.php?tx={$txId}",
            'cancel_url' => SITE_URL . "/user/add-funds.php"
        ];

        $res = $this->makeRequest($this->getBaseUrl() . '/invoice', 'POST', $payload, $headers);

        if (!$res['success'] || empty($res['data']['invoice_url'])) {
            $msg = $res['data']['message'] ?? $res['error'] ?? 'NOWPayments invoice generation failed';
            return ['status' => 'error', 'error' => 'NOWPayments API error: ' . $msg];
        }

        $invoice = $res['data'];

        return [
            'status' => 'redirect',
            'redirect_url' => $invoice['invoice_url'],
            'gateway_ref' => (string)($invoice['id'] ?? $txId)
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $ipnSecret = $this->getCredential('ipn_secret');
        if (empty($ipnSecret)) {
            return ['verified' => false, 'error' => 'NOWPayments IPN secret not configured'];
        }

        $receivedSig = $headers['x-nowpayments-sig'] ?? $headers['X-NOWPAYMENTS-SIG'] ?? null;
        if (empty($receivedSig)) {
            return ['verified' => false, 'error' => 'Missing x-nowpayments-sig header'];
        }

        $data = json_decode($rawPayload, true);
        if (!is_array($data)) {
            return ['verified' => false, 'error' => 'Invalid JSON payload from NOWPayments'];
        }

        // NOWPayments IPN verification requires sorting data alphabetically by key
        ksort($data);
        $sortedJson = json_encode($data, JSON_UNESCAPED_SLASHES);
        $computedSig = hash_hmac('sha512', (string)$sortedJson, $ipnSecret);

        if (!hash_equals($computedSig, $receivedSig)) {
            return ['verified' => false, 'error' => 'NOWPayments IPN signature mismatch'];
        }

        $paymentStatus = strtolower($data['payment_status'] ?? '');
        $txId = $data['order_id'] ?? null;
        $paymentId = (string)($data['payment_id'] ?? $data['invoice_id'] ?? $txId);
        $amount = (float)($data['price_amount'] ?? $data['actually_paid'] ?? 0);
        $currency = strtoupper($data['price_currency'] ?? 'USD');

        // Status 'finished' indicates completed deposit
        if ($paymentStatus === 'finished') {
            return [
                'verified' => true,
                'event_id' => $paymentId,
                'transaction_id' => $txId,
                'gateway_ref' => $paymentId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed'
            ];
        }

        if (in_array($paymentStatus, ['failed', 'expired'])) {
            return [
                'verified' => true,
                'event_id' => $paymentId,
                'transaction_id' => $txId,
                'status' => 'failed',
                'error' => "NOWPayments status: {$paymentStatus}"
            ];
        }

        return [
            'verified' => true,
            'event_id' => $paymentId,
            'transaction_id' => $txId,
            'status' => 'pending'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $headers = ['x-api-key: ' . $this->getCredential('api_key')];
        $res = $this->makeRequest($this->getBaseUrl() . "/payment/{$gatewayRef}", 'GET', null, $headers);

        if ($res['success'] && ($res['data']['payment_status'] ?? '') === 'finished') {
            return [
                'status' => 'completed',
                'gateway_ref' => (string)$res['data']['payment_id'],
                'amount' => (float)$res['data']['price_amount'],
                'currency' => strtoupper($res['data']['price_currency'])
            ];
        }

        return ['status' => 'pending'];
    }
}
