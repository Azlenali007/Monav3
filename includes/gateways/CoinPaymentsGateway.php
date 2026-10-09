<?php
/**
 * Mona SMM Panel v2 - CoinPayments Gateway Adapter (Cryptocurrency)
 * Official API: CoinPayments API & HMAC-SHA512 IPN Verification
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class CoinPaymentsGateway extends AbstractGateway {
    public function getCode(): string {
        return 'coinpayments';
    }

    public function getName(): string {
        return 'CoinPayments';
    }

    public function getType(): string {
        return 'crypto';
    }

    public function getConfigFields(): array {
        return [
            'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            'ipn_secret' => ['label' => 'IPN Secret', 'type' => 'password', 'required' => true],
            'public_key' => ['label' => 'Public API Key', 'type' => 'text', 'required' => false],
            'private_key' => ['label' => 'Private API Key', 'type' => 'password', 'required' => false]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('merchant_id')) && !empty($this->getCredential('ipn_secret'));
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'CoinPayments credentials not configured'];
        }

        $merchantId = $this->getCredential('merchant_id');
        $txId = $payment['transaction_id'];
        $amount = number_format((float)$payment['amount'], 2, '.', '');
        $currency = strtoupper($payment['currency'] ?? 'USD');

        // Simple CoinPayments Hosted Form redirect
        $params = [
            'cmd' => '_pay_simple',
            'reset' => '1',
            'merchant' => $merchantId,
            'item_name' => "Wallet Deposit #{$payment['id']}",
            'item_number' => $txId,
            'amountf' => $amount,
            'currency' => $currency,
            'custom' => $txId,
            'email' => $user['email'],
            'ipn_url' => SITE_URL . "/api/payments.php?gateway=coinpayments",
            'success_url' => SITE_URL . "/user/payment-status.php?tx={$txId}",
            'cancel_url' => SITE_URL . "/user/add-funds.php"
        ];

        $checkoutUrl = 'https://www.coinpayments.net/index.php?' . http_build_query($params);

        return [
            'status' => 'redirect',
            'redirect_url' => $checkoutUrl,
            'gateway_ref' => $txId
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $ipnSecret = $this->getCredential('ipn_secret');
        $merchantId = $this->getCredential('merchant_id');

        if (empty($ipnSecret) || empty($merchantId)) {
            return ['verified' => false, 'error' => 'CoinPayments credentials not configured'];
        }

        $hmac = $headers['hmac'] ?? $headers['HTTP_HMAC'] ?? $_SERVER['HTTP_HMAC'] ?? null;
        if (empty($hmac)) {
            return ['verified' => false, 'error' => 'Missing CoinPayments HMAC signature header'];
        }

        $calculatedHmac = hash_hmac('sha512', $rawPayload, $ipnSecret);
        if (!hash_equals($calculatedHmac, $hmac)) {
            return ['verified' => false, 'error' => 'CoinPayments HMAC verification failure'];
        }

        if (($postData['merchant'] ?? '') !== $merchantId) {
            return ['verified' => false, 'error' => 'CoinPayments merchant ID mismatch'];
        }

        $status = (int)($postData['status'] ?? 0);
        $txId = $postData['custom'] ?? $postData['item_number'] ?? null;
        $txnId = $postData['txn_id'] ?? $txId;
        $amount = (float)($postData['amount1'] ?? $postData['amount'] ?? 0);
        $currency = strtoupper($postData['currency1'] ?? 'USD');

        // Status >= 100 or status == 2 indicates payment complete and confirmed
        if ($status >= 100 || $status === 2) {
            return [
                'verified' => true,
                'event_id' => $txnId,
                'transaction_id' => $txId,
                'gateway_ref' => $txnId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed'
            ];
        }

        if ($status < 0) {
            return [
                'verified' => true,
                'event_id' => $txnId,
                'transaction_id' => $txId,
                'status' => 'failed',
                'error' => "CoinPayments error status: {$status} (" . ($postData['status_text'] ?? '') . ")"
            ];
        }

        return [
            'verified' => true,
            'event_id' => $txnId,
            'transaction_id' => $txId,
            'status' => 'pending'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        return ['status' => 'pending'];
    }
}
