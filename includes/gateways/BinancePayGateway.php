<?php
/**
 * Mona SMM Panel v2 - Binance Pay Gateway Adapter (Cryptocurrency)
 * Official API: Binance Pay v3 Merchant API & HMAC-SHA512 Signature
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class BinancePayGateway extends AbstractGateway {
    public function getCode(): string {
        return 'binancepay';
    }

    public function getName(): string {
        return 'Binance Pay';
    }

    public function getType(): string {
        return 'crypto';
    }

    public function getConfigFields(): array {
        return [
            'api_key' => ['label' => 'API Key / Certificate SN', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('api_key')) && !empty($this->getCredential('secret_key'));
    }

    private function generateNonce(int $length = 32): string {
        return bin2hex(random_bytes((int)($length / 2)));
    }

    private function signPayload(string $timestamp, string $nonce, string $body): string {
        $secretKey = $this->getCredential('secret_key');
        $payloadToSign = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        return strtoupper(hash_hmac('sha512', $payloadToSign, $secretKey));
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Binance Pay credentials not configured'];
        }

        $apiKey = $this->getCredential('api_key');
        $txId = $payment['transaction_id'];
        $amount = number_format((float)$payment['amount'], 2, '.', '');
        $currency = strtoupper($payment['currency'] ?? 'USDT');

        $body = [
            'env' => [
                'terminalType' => 'WEB'
            ],
            'merchantTradeNo' => $txId,
            'orderAmount' => (float)$amount,
            'currency' => $currency,
            'goods' => [
                'goodsType' => '02',
                'goodsCategory' => 'Z000',
                'referenceGoodsId' => (string)$payment['id'],
                'goodsName' => "Wallet Deposit #{$payment['id']}",
                'goodsDetail' => "Deposit for {$user['username']}"
            ],
            'returnUrl' => SITE_URL . "/user/payment-status.php?tx={$txId}",
            'cancelUrl' => SITE_URL . "/user/add-funds.php"
        ];

        $jsonBody = json_encode($body);
        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = $this->generateNonce();
        $signature = $this->signPayload($timestamp, $nonce, $jsonBody);

        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature
        ];

        $res = $this->makeRequest('https://bpay.binanceapi.com/binancepay/openapi/v3/order', 'POST', $body, $headers);

        if (!$res['success'] || ($res['data']['status'] ?? '') !== 'SUCCESS') {
            $msg = $res['data']['errorMessage'] ?? $res['error'] ?? 'Binance Pay order creation failed';
            return ['status' => 'error', 'error' => 'Binance Pay API error: ' . $msg];
        }

        $checkoutUrl = $res['data']['data']['universalUrl'] ?? $res['data']['data']['checkoutUrl'] ?? null;
        $prepayId = $res['data']['data']['prepayId'] ?? $txId;

        return [
            'status' => 'redirect',
            'redirect_url' => $checkoutUrl,
            'gateway_ref' => $prepayId
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $secretKey = $this->getCredential('secret_key');
        if (empty($secretKey)) {
            return ['verified' => false, 'error' => 'Binance Pay secret not configured'];
        }

        $timestamp = $headers['binancepay-timestamp'] ?? $headers['Binancepay-Timestamp'] ?? null;
        $nonce = $headers['binancepay-nonce'] ?? $headers['Binancepay-Nonce'] ?? null;
        $signature = $headers['binancepay-signature'] ?? $headers['Binancepay-Signature'] ?? null;

        if (empty($timestamp) || empty($nonce) || empty($signature)) {
            return ['verified' => false, 'error' => 'Missing Binance Pay webhook security headers'];
        }

        $expectedSig = $this->signPayload((string)$timestamp, (string)$nonce, $rawPayload);

        if (!hash_equals(strtoupper($expectedSig), strtoupper($signature))) {
            return ['verified' => false, 'error' => 'Binance Pay signature verification mismatch'];
        }

        $data = json_decode($rawPayload, true);
        if (!is_array($data) || empty($data['bizStatus'])) {
            return ['verified' => false, 'error' => 'Invalid Binance Pay payload'];
        }

        $bizStatus = $data['bizStatus'];
        $bizData = json_decode($data['data'] ?? '{}', true) ?: [];
        $txId = $bizData['merchantTradeNo'] ?? null;
        $prepayId = $bizData['prepayId'] ?? $txId;
        $amount = (float)($bizData['totalFee'] ?? 0);
        $currency = strtoupper($bizData['currency'] ?? 'USDT');

        if ($bizStatus === 'PAY_SUCCESS') {
            return [
                'verified' => true,
                'event_id' => $prepayId,
                'transaction_id' => $txId,
                'gateway_ref' => $prepayId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed'
            ];
        }

        return [
            'verified' => true,
            'event_id' => $prepayId,
            'transaction_id' => $txId,
            'status' => 'failed',
            'error' => "Binance Pay status: {$bizStatus}"
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $apiKey = $this->getCredential('api_key');
        $body = ['merchantTradeNo' => $transactionId ?? $gatewayRef];
        $jsonBody = json_encode($body);
        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = $this->generateNonce();
        $signature = $this->signPayload($timestamp, $nonce, $jsonBody);

        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $apiKey,
            'BinancePay-Signature: ' . $signature
        ];

        $res = $this->makeRequest('https://bpay.binanceapi.com/binancepay/openapi/v3/order/query', 'POST', $body, $headers);

        if ($res['success'] && ($res['data']['data']['status'] ?? '') === 'PAID') {
            return [
                'status' => 'completed',
                'gateway_ref' => $res['data']['data']['transactionId'] ?? $gatewayRef,
                'amount' => (float)$res['data']['data']['orderAmount'],
                'currency' => strtoupper($res['data']['data']['currency'])
            ];
        }

        return ['status' => 'pending'];
    }
}
