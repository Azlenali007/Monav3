<?php
/**
 * Mona SMM Panel v2 - PayU Payment Gateway Adapter (India)
 * Official API: PayU Hosted Checkout & Reverse Hash Verification
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class PayUGateway extends AbstractGateway {
    public function getCode(): string {
        return 'payu';
    }

    public function getName(): string {
        return 'PayU';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'merchant_key' => ['label' => 'Merchant Key', 'type' => 'text', 'required' => true],
            'merchant_salt' => ['label' => 'Merchant Salt', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('merchant_key')) && !empty($this->getCredential('merchant_salt'));
    }

    private function getCheckoutUrl(): string {
        return $this->isSandbox() ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment';
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'PayU credentials not configured'];
        }

        $key = $this->getCredential('merchant_key');
        $salt = $this->getCredential('merchant_salt');
        $txnid = $payment['transaction_id'];
        $amount = number_format((float)$payment['amount'], 2, '.', '');
        $productinfo = "Wallet Deposit #" . $payment['id'];
        $firstname = preg_replace('/[^a-zA-Z0-9]/', '', $user['username']) ?: 'Customer';
        $email = $user['email'];

        // Hash sequence: key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||SALT
        $hashString = "{$key}|{$txnid}|{$amount}|{$productinfo}|{$firstname}|{$email}|||||||||||{$salt}";
        $hash = strtolower(hash('sha512', $hashString));

        $postParams = [
            'key' => $key,
            'txnid' => $txnid,
            'amount' => $amount,
            'productinfo' => $productinfo,
            'firstname' => $firstname,
            'email' => $email,
            'phone' => '9999999999',
            'surl' => SITE_URL . "/api/payments.php?gateway=payu",
            'furl' => SITE_URL . "/api/payments.php?gateway=payu",
            'hash' => $hash,
            'service_provider' => 'payu_paisa'
        ];

        return [
            'status' => 'custom',
            'gateway_ref' => $txnid,
            'custom_data' => [
                'action_url' => $this->getCheckoutUrl(),
                'params' => $postParams
            ]
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $salt = $this->getCredential('merchant_salt');
        $key = $this->getCredential('merchant_key');

        if (empty($salt) || empty($key)) {
            return ['verified' => false, 'error' => 'PayU credentials not configured'];
        }

        $status = $postData['status'] ?? '';
        $firstname = $postData['firstname'] ?? '';
        $amount = $postData['amount'] ?? '';
        $txnid = $postData['txnid'] ?? '';
        $postedHash = $postData['hash'] ?? '';
        $productinfo = $postData['productinfo'] ?? '';
        $email = $postData['email'] ?? '';
        $payuMoneyId = $postData['payuMoneyId'] ?? $postData['mihpayid'] ?? $txnid;
        $additionalCharges = $postData['additionalCharges'] ?? null;

        if (empty($postedHash) || empty($status) || empty($txnid)) {
            return ['verified' => false, 'error' => 'Missing PayU response parameters'];
        }

        // Reverse hash verification
        if ($additionalCharges !== null && $additionalCharges !== '') {
            $reverseHashString = "{$additionalCharges}|{$salt}|{$status}|||||||||||{$email}|{$firstname}|{$productinfo}|{$amount}|{$txnid}|{$key}";
        } else {
            $reverseHashString = "{$salt}|{$status}|||||||||||{$email}|{$firstname}|{$productinfo}|{$amount}|{$txnid}|{$key}";
        }

        $computedHash = strtolower(hash('sha512', $reverseHashString));

        if (!hash_equals($computedHash, strtolower($postedHash))) {
            return ['verified' => false, 'error' => 'PayU reverse hash verification mismatch'];
        }

        if ($status === 'success') {
            return [
                'verified' => true,
                'event_id' => $payuMoneyId,
                'transaction_id' => $txnid,
                'gateway_ref' => $payuMoneyId,
                'amount' => (float)$amount,
                'currency' => 'INR',
                'status' => 'completed'
            ];
        }

        return [
            'verified' => true,
            'event_id' => $payuMoneyId,
            'transaction_id' => $txnid,
            'status' => 'failed',
            'error' => "PayU payment failed with status: {$status}"
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        // PayU provides verify_payment web service API
        return ['status' => 'pending'];
    }
}
