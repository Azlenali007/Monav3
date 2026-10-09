<?php
/**
 * Mona SMM Panel v2 - PayPal Checkout Adapter (International)
 * Official API: PayPal v2 Orders API & Server-Side Capture
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class PayPalGateway extends AbstractGateway {
    public function getCode(): string {
        return 'paypal';
    }

    public function getName(): string {
        return 'PayPal Checkout';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => true],
            'client_secret' => ['label' => 'Client Secret', 'type' => 'password', 'required' => true],
            'webhook_id' => ['label' => 'Webhook ID (Optional)', 'type' => 'text', 'required' => false]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('client_id')) && !empty($this->getCredential('client_secret'));
    }

    private function getBaseUrl(): string {
        return $this->isSandbox() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function getAccessToken(): ?string {
        $clientId = $this->getCredential('client_id');
        $secret = $this->getCredential('client_secret');

        $ch = curl_init($this->getBaseUrl() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => "{$clientId}:{$secret}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 20
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        $json = json_decode((string)$res, true);
        return $json['access_token'] ?? null;
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'PayPal credentials not configured'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['status' => 'error', 'error' => 'Failed to obtain PayPal OAuth2 access token'];
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $payment['transaction_id'],
                    'custom_id' => $payment['transaction_id'],
                    'description' => "Wallet Deposit for {$user['username']}",
                    'amount' => [
                        'currency_code' => strtoupper($payment['currency'] ?? 'USD'),
                        'value' => number_format((float)$payment['amount'], 2, '.', '')
                    ]
                ]
            ],
            'application_context' => [
                'brand_name' => SITE_NAME,
                'user_action' => 'PAY_NOW',
                'return_url' => SITE_URL . "/user/payment-status.php?tx={$payment['transaction_id']}&gateway=paypal",
                'cancel_url' => SITE_URL . "/user/add-funds.php"
            ]
        ];

        $res = $this->makeRequest($this->getBaseUrl() . '/v2/checkout/orders', 'POST', $payload, $headers);

        if (!$res['success'] || empty($res['data']['id'])) {
            $msg = $res['data']['message'] ?? $res['error'] ?? 'PayPal order creation failed';
            return ['status' => 'error', 'error' => 'PayPal API error: ' . $msg];
        }

        $orderId = $res['data']['id'];
        $approveUrl = null;

        foreach ($res['data']['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approveUrl = $link['href'];
                break;
            }
        }

        if (!$approveUrl) {
            return ['status' => 'error', 'error' => 'PayPal did not return approval link'];
        }

        return [
            'status' => 'redirect',
            'redirect_url' => $approveUrl,
            'gateway_ref' => $orderId
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        // Handle client return with PayPal token parameter (order_id)
        $orderId = $_GET['token'] ?? $postData['order_id'] ?? null;
        $transactionId = $_GET['tx'] ?? null;

        // If standard PayPal webhook event
        $event = json_decode($rawPayload, true);
        if (is_array($event) && isset($event['event_type'])) {
            $eventType = $event['event_type'];
            $resource = $event['resource'] ?? [];
            $eventId = $event['id'] ?? null;

            if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
                $orderId = $resource['id'] ?? null;
            } elseif ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? null;
                $customId = $resource['custom_id'] ?? null;
                $amount = (float)($resource['amount']['value'] ?? 0);
                $currency = strtoupper($resource['amount']['currency_code'] ?? 'USD');

                return [
                    'verified' => true,
                    'event_id' => $eventId,
                    'transaction_id' => $customId,
                    'gateway_ref' => $orderId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'completed'
                ];
            }
        }

        if (!$orderId) {
            return ['verified' => false, 'error' => 'Missing PayPal order identifier'];
        }

        // Authoritative server-side order capture/lookup
        $statusCheck = $this->captureOrder($orderId);
        if ($statusCheck['status'] === 'completed') {
            return [
                'verified' => true,
                'event_id' => $orderId,
                'transaction_id' => $statusCheck['custom_id'] ?? $transactionId,
                'gateway_ref' => $orderId,
                'amount' => $statusCheck['amount'],
                'currency' => $statusCheck['currency'],
                'status' => 'completed'
            ];
        }

        return [
            'verified' => false,
            'error' => $statusCheck['error'] ?? 'PayPal order not captured'
        ];
    }

    private function captureOrder(string $orderId): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['status' => 'error', 'error' => 'No access token'];
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        // First capture the order
        $res = $this->makeRequest($this->getBaseUrl() . "/v2/checkout/orders/{$orderId}/capture", 'POST', (object)[], $headers);

        if (!$res['success']) {
            // It might already be captured, check details
            $res = $this->makeRequest($this->getBaseUrl() . "/v2/checkout/orders/{$orderId}", 'GET', null, $headers);
        }

        if ($res['success'] && in_array($res['data']['status'] ?? '', ['COMPLETED', 'APPROVED'])) {
            $unit = $res['data']['purchase_units'][0] ?? [];
            $capture = $unit['payments']['captures'][0] ?? [];
            $amount = (float)($capture['amount']['value'] ?? $unit['amount']['value'] ?? 0);
            $currency = strtoupper($capture['amount']['currency_code'] ?? $unit['amount']['currency_code'] ?? 'USD');
            $customId = $unit['custom_id'] ?? $unit['reference_id'] ?? null;

            return [
                'status' => 'completed',
                'amount' => $amount,
                'currency' => $currency,
                'custom_id' => $customId
            ];
        }

        return ['status' => 'pending', 'error' => $res['error'] ?? 'Incomplete status'];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        return $this->captureOrder($gatewayRef);
    }
}
