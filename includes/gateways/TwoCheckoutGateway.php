<?php
/**
 * Mona SMM Panel v2 - Verifone / 2Checkout Gateway Adapter (International)
 * Official API: 2Checkout Hosted BuyLink & INS (Instant Notification Service)
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class TwoCheckoutGateway extends AbstractGateway {
    public function getCode(): string {
        return 'twocheckout';
    }

    public function getName(): string {
        return 'Verifone / 2Checkout';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'merchant_code' => ['label' => 'Merchant Code / Account #', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'Secret Key (INS)', 'type' => 'password', 'required' => true],
            'buy_link_secret' => ['label' => 'BuyLink Secret Word', 'type' => 'password', 'required' => false]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('merchant_code')) && !empty($this->getCredential('secret_key'));
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => '2Checkout credentials not configured'];
        }

        $merchantCode = $this->getCredential('merchant_code');
        $txId = $payment['transaction_id'];
        $amount = number_format((float)$payment['amount'], 2, '.', '');
        $currency = strtoupper($payment['currency'] ?? 'USD');

        $params = [
            'merchant' => $merchantCode,
            'order-ext-ref' => $txId,
            'item-ext-ref' => $txId,
            'prod' => "Wallet Deposit #{$payment['id']}",
            'price' => $amount,
            'qty' => 1,
            'type' => 'PRODUCT',
            'currency' => $currency,
            'customer-ext-ref' => (string)$user['id'],
            'name' => $user['username'],
            'email' => $user['email'],
            'return-url' => SITE_URL . "/user/payment-status.php?tx={$txId}",
            'return-type' => 'redirect',
            'test' => $this->isSandbox() ? '1' : '0'
        ];

        $checkoutUrl = 'https://secure.2checkout.com/checkout/buy?' . http_build_query($params);

        return [
            'status' => 'redirect',
            'redirect_url' => $checkoutUrl,
            'gateway_ref' => $txId
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $secretKey = $this->getCredential('secret_key');
        if (empty($secretKey)) {
            return ['verified' => false, 'error' => '2Checkout secret key not configured'];
        }

        // 2Checkout INS sends form-encoded POST variables
        if (empty($postData) || !isset($postData['HASH'])) {
            return ['verified' => false, 'error' => 'Missing 2Checkout INS payload'];
        }

        $receivedHash = $postData['HASH'];
        $hashString = '';

        // INS signature algorithm: length-prefixed values sorted by field specification
        foreach ($postData as $key => $val) {
            if ($key !== 'HASH' && !is_array($val)) {
                $hashString .= strlen((string)$val) . (string)$val;
            }
        }

        $expectedHash = hash_hmac('md5', $hashString, $secretKey);

        if (!hash_equals(strtolower($expectedHash), strtolower($receivedHash))) {
            return ['verified' => false, 'error' => '2Checkout INS signature verification mismatch'];
        }

        $orderStatus = $postData['ORDERSTATUS'] ?? '';
        $txId = $postData['REFNOEXT'] ?? null;
        $refNo = $postData['REFNO'] ?? null;
        $total = (float)($postData['IPN_TOTALGENERAL'] ?? 0);
        $currency = strtoupper($postData['CURRENCY'] ?? 'USD');

        if (in_array($orderStatus, ['COMPLETE', 'AUTHRECEIVED'])) {
            return [
                'verified' => true,
                'event_id' => $refNo ?: $txId,
                'transaction_id' => $txId,
                'gateway_ref' => $refNo,
                'amount' => $total,
                'currency' => $currency,
                'status' => 'completed'
            ];
        }

        return [
            'verified' => true,
            'event_id' => $refNo,
            'transaction_id' => $txId,
            'status' => 'pending'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        return ['status' => 'pending'];
    }
}
