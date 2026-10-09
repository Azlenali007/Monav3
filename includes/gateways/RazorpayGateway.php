<?php
/**
 * Mona SMM Panel v2 - Razorpay Gateway Adapter (India)
 * Official API: Orders API v1 + HMAC-SHA256 Webhook Verification
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class RazorpayGateway extends AbstractGateway {
    public function getCode(): string {
        return 'razorpay';
    }

    public function getName(): string {
        return 'Razorpay';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'key_id' => ['label' => 'Key ID', 'type' => 'text', 'required' => true],
            'key_secret' => ['label' => 'Key Secret', 'type' => 'password', 'required' => true],
            'webhook_secret' => ['label' => 'Webhook Secret', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('key_id')) && !empty($this->getCredential('key_secret'));
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Razorpay credentials not configured'];
        }

        $amountInSubunits = (int)round((float)$payment['amount'] * 100);
        $currency = strtoupper($payment['currency'] ?? 'INR');

        $headers = [
            'Authorization: Basic ' . base64_encode($this->getCredential('key_id') . ':' . $this->getCredential('key_secret'))
        ];

        $payload = [
            'amount' => $amountInSubunits,
            'currency' => $currency,
            'receipt' => $payment['transaction_id'],
            'notes' => [
                'user_id' => (string)$user['id'],
                'transaction_id' => $payment['transaction_id']
            ]
        ];

        $res = $this->makeRequest('https://api.razorpay.com/v1/orders', 'POST', $payload, $headers);

        if (!$res['success'] || empty($res['data']['id'])) {
            $err = $res['data']['error']['description'] ?? $res['error'] ?? 'Order creation failed';
            return ['status' => 'error', 'error' => 'Razorpay API error: ' . $err];
        }

        $orderId = $res['data']['id'];

        return [
            'status' => 'custom',
            'gateway_ref' => $orderId,
            'custom_data' => [
                'key_id' => $this->getCredential('key_id'),
                'order_id' => $orderId,
                'amount' => $amountInSubunits,
                'currency' => $currency,
                'name' => SITE_NAME,
                'description' => "Deposit for {$user['username']}",
                'prefill' => [
                    'name' => $user['username'],
                    'email' => $user['email']
                ],
                'notes' => [
                    'transaction_id' => $payment['transaction_id']
                ]
            ]
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $webhookSecret = $this->getCredential('webhook_secret');
        if (empty($webhookSecret)) {
            return ['verified' => false, 'error' => 'Razorpay webhook secret not configured'];
        }

        $signature = $headers['x-razorpay-signature'] ?? $headers['X-Razorpay-Signature'] ?? null;
        if (empty($signature)) {
            return ['verified' => false, 'error' => 'Missing X-Razorpay-Signature header'];
        }

        $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            return ['verified' => false, 'error' => 'Invalid Razorpay webhook signature'];
        }

        $event = json_decode($rawPayload, true);
        if (!is_array($event) || empty($event['event'])) {
            return ['verified' => false, 'error' => 'Invalid JSON payload'];
        }

        $eventId = $event['id'] ?? null;
        $eventName = $event['event'];

        if ($eventName !== 'payment.captured' && $eventName !== 'order.paid') {
            return [
                'verified' => true,
                'event_id' => $eventId,
                'status' => 'pending',
                'error' => "Ignored event type: {$eventName}"
            ];
        }

        $entity = $event['payload']['payment']['entity'] ?? $event['payload']['order']['entity'] ?? [];
        $notes = $entity['notes'] ?? [];
        $transactionId = $notes['transaction_id'] ?? $entity['receipt'] ?? null;
        $orderId = $entity['order_id'] ?? $entity['id'] ?? null;
        $amount = isset($entity['amount']) ? ((float)$entity['amount'] / 100) : 0.0;
        $currency = strtoupper($entity['currency'] ?? 'INR');

        return [
            'verified' => true,
            'event_id' => $eventId,
            'transaction_id' => $transactionId,
            'gateway_ref' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($this->getCredential('key_id') . ':' . $this->getCredential('key_secret'))
        ];

        $res = $this->makeRequest("https://api.razorpay.com/v1/orders/{$gatewayRef}/payments", 'GET', null, $headers);

        if (!$res['success'] || !isset($res['data']['items'])) {
            return ['status' => 'error', 'error' => 'Failed to query Razorpay order status'];
        }

        foreach ($res['data']['items'] as $item) {
            if (($item['status'] ?? '') === 'captured') {
                return [
                    'status' => 'completed',
                    'gateway_ref' => $item['id'],
                    'amount' => (float)$item['amount'] / 100,
                    'currency' => strtoupper($item['currency'])
                ];
            }
        }

        return ['status' => 'pending'];
    }
}
