<?php
/**
 * Mona SMM Panel v2 - Stripe Checkout Adapter (International)
 * Official API: Stripe Checkout Sessions API & HMAC-SHA256 Webhook Verification
 * Built using native PHP cURL (zero Composer SDK dependency required)
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/AbstractGateway.php';

class StripeGateway extends AbstractGateway {
    public function getCode(): string {
        return 'stripe';
    }

    public function getName(): string {
        return 'Stripe Checkout';
    }

    public function getType(): string {
        return 'fiat';
    }

    public function getConfigFields(): array {
        return [
            'publishable_key' => ['label' => 'Publishable Key', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true],
            'webhook_secret' => ['label' => 'Webhook Signing Secret (whsec_...)', 'type' => 'password', 'required' => true]
        ];
    }

    public function isConfigured(): bool {
        return !empty($this->getCredential('secret_key'));
    }

    public function createPayment(array $payment, array $user): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Stripe credentials not configured'];
        }

        $secretKey = $this->getCredential('secret_key');
        $currency = strtolower($payment['currency'] ?? 'usd');
        $unitAmount = (int)round((float)$payment['amount'] * 100);

        $postFields = [
            'success_url' => SITE_URL . "/user/payment-status.php?tx={$payment['transaction_id']}&session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => SITE_URL . "/user/add-funds.php",
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'client_reference_id' => $payment['transaction_id'],
            'customer_email' => $user['email'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $unitAmount,
                        'product_data' => [
                            'name' => "Wallet Deposit (#{$payment['transaction_id']})",
                            'description' => "Deposit for user: {$user['username']}"
                        ]
                    ],
                    'quantity' => 1
                ]
            ],
            'metadata' => [
                'transaction_id' => $payment['transaction_id'],
                'user_id' => (string)$user['id']
            ]
        ];

        // Format nested arrays for Stripe form-urlencoded requirements
        $encodedPayload = http_build_query($postFields);

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 25
        ]);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['status' => 'error', 'error' => 'Stripe connection failure: ' . $err];
        }

        $session = json_decode((string)$res, true);

        if (empty($session['url']) || empty($session['id'])) {
            $msg = $session['error']['message'] ?? 'Checkout session creation failed';
            return ['status' => 'error', 'error' => 'Stripe API error: ' . $msg];
        }

        return [
            'status' => 'redirect',
            'redirect_url' => $session['url'],
            'gateway_ref' => $session['id']
        ];
    }

    public function verifyWebhook(array $headers, string $rawPayload, array $postData): array {
        $webhookSecret = $this->getCredential('webhook_secret');
        if (empty($webhookSecret)) {
            return ['verified' => false, 'error' => 'Stripe webhook secret not configured'];
        }

        $sigHeader = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? null;
        if (empty($sigHeader)) {
            return ['verified' => false, 'error' => 'Missing Stripe-Signature header'];
        }

        // Parse signature header: t=1492774577,v1=5257a869e7ecebeda32affa...
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $sigHeader) as $part) {
            $item = explode('=', trim($part), 2);
            if (count($item) === 2) {
                if ($item[0] === 't') {
                    $timestamp = (int)$item[1];
                } elseif ($item[0] === 'v1') {
                    $signatures[] = $item[1];
                }
            }
        }

        if ($timestamp === null || empty($signatures)) {
            return ['verified' => false, 'error' => 'Malformed Stripe-Signature header'];
        }

        // Check timestamp tolerance (5 minutes) to protect against replay attacks
        if (abs(time() - $timestamp) > 300) {
            return ['verified' => false, 'error' => 'Stripe webhook signature timestamp expired'];
        }

        // Compute expected HMAC SHA-256 signature
        $signedPayload = "{$timestamp}.{$rawPayload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        $signatureMatches = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                $signatureMatches = true;
                break;
            }
        }

        if (!$signatureMatches) {
            return ['verified' => false, 'error' => 'Stripe signature verification failed'];
        }

        $event = json_decode($rawPayload, true);
        if (!is_array($event) || empty($event['type'])) {
            return ['verified' => false, 'error' => 'Invalid Stripe JSON payload'];
        }

        $eventId = $event['id'] ?? null;
        $eventType = $event['type'];

        if ($eventType !== 'checkout.session.completed') {
            return [
                'verified' => true,
                'event_id' => $eventId,
                'status' => 'pending',
                'error' => "Ignored Stripe event type: {$eventType}"
            ];
        }

        $session = $event['data']['object'] ?? [];
        $transactionId = $session['client_reference_id'] ?? ($session['metadata']['transaction_id'] ?? null);
        $amount = isset($session['amount_total']) ? ((float)$session['amount_total'] / 100) : 0.0;
        $currency = strtoupper($session['currency'] ?? 'USD');
        $paymentIntent = $session['payment_intent'] ?? $session['id'] ?? null;

        return [
            'verified' => true,
            'event_id' => $eventId,
            'transaction_id' => $transactionId,
            'gateway_ref' => $paymentIntent,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed'
        ];
    }

    public function checkPaymentStatus(string $gatewayRef, ?string $transactionId = null): array {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Not configured'];
        }

        $secretKey = $this->getCredential('secret_key');
        $headers = ['Authorization: Bearer ' . $secretKey];

        // Could be a session id (cs_...) or payment_intent (pi_...)
        $url = str_starts_with($gatewayRef, 'cs_')
            ? "https://api.stripe.com/v1/checkout/sessions/{$gatewayRef}"
            : "https://api.stripe.com/v1/payment_intents/{$gatewayRef}";

        $res = $this->makeRequest($url, 'GET', null, $headers);

        if ($res['success']) {
            $status = $res['data']['status'] ?? ($res['data']['payment_status'] ?? '');
            if (in_array($status, ['paid', 'succeeded', 'complete'])) {
                $amount = isset($res['data']['amount_total'])
                    ? ((float)$res['data']['amount_total'] / 100)
                    : ((float)($res['data']['amount'] ?? 0) / 100);

                return [
                    'status' => 'completed',
                    'gateway_ref' => $res['data']['id'],
                    'amount' => $amount,
                    'currency' => strtoupper($res['data']['currency'] ?? 'USD')
                ];
            }
        }

        return ['status' => 'pending'];
    }
}
