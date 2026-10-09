<?php
/**
 * Mona SMM Panel v2 - Central Webhook & Payment Notification Listener
 * Strictly handles server-to-server callbacks, idempotency, and signature verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways/GatewayManager.php';

use Mona\Gateways\GatewayManager;

// Normalize request headers
$headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
}
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$headerName] = $value;
    }
}

// Retrieve raw payload
$rawPayload = file_get_contents('php://input') ?: '';

// Detect Gateway Code
$gatewayCode = strtolower(trim((string)($_GET['gateway'] ?? $_POST['gateway'] ?? '')));

// Handle direct Stripe header or PayPal query indications if param missing
if (empty($gatewayCode)) {
    if (isset($headers['stripe-signature'])) {
        $gatewayCode = 'stripe';
    } elseif (isset($headers['x-razorpay-signature'])) {
        $gatewayCode = 'razorpay';
    } elseif (isset($headers['x-webhook-signature'])) {
        $gatewayCode = 'cashfree';
    } elseif (isset($headers['x-verify'])) {
        $gatewayCode = 'phonepe';
    } elseif (isset($headers['x-nowpayments-sig'])) {
        $gatewayCode = 'nowpayments';
    } elseif (isset($headers['binancepay-signature'])) {
        $gatewayCode = 'binancepay';
    }
}

if (empty($gatewayCode)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Missing gateway identifier']);
    exit;
}

$pdo = getDB();
$gateway = GatewayManager::getGateway($gatewayCode, $pdo);

if (!$gateway) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unknown payment gateway']);
    exit;
}

// Execute gateway-specific cryptographic signature and authenticity verification
$verification = $gateway->verifyWebhook($headers, $rawPayload, $_POST);

if (!$verification['verified']) {
    error_log("Webhook verification rejected for {$gatewayCode}: " . ($verification['error'] ?? 'Unknown error'));
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Webhook signature verification failed']);
    exit;
}

// Check for event deduplication via payment_webhook_events table
$eventId = $verification['event_id'] ?? null;
if ($eventId) {
    $dedupStmt = $pdo->prepare("SELECT id FROM payment_webhook_events WHERE gateway = :gw AND event_id = :ev LIMIT 1");
    $dedupStmt->execute([':gw' => $gatewayCode, ':ev' => $eventId]);
    if ($dedupStmt->fetch()) {
        // Idempotent success response to prevent duplicate provider retries
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Event already processed']);
        exit;
    }
}

// Locate matching payment record
$txId = $verification['transaction_id'] ?? null;
$gwRef = $verification['gateway_ref'] ?? null;
$payment = null;

if ($txId) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE transaction_id = :tx LIMIT 1");
    $stmt->execute([':tx' => $txId]);
    $payment = $stmt->fetch();
}

if (!$payment && $gwRef) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE gateway_ref = :ref LIMIT 1");
    $stmt->execute([':ref' => $gwRef]);
    $payment = $stmt->fetch();
}

if (!$payment) {
    error_log("Webhook received for unknown payment: tx={$txId}, ref={$gwRef}");
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Payment reference not found']);
    exit;
}

// Process Wallet Crediting if payment completed
if (($verification['status'] ?? '') === 'completed') {
    $amount = (float)($verification['amount'] ?? $payment['net_amount']);
    $currency = strtoupper((string)($verification['currency'] ?? $payment['currency']));
    
    $success = GatewayManager::processPaymentCredit(
        $pdo,
        (int)$payment['id'],
        (string)($gwRef ?: $payment['transaction_id']),
        $amount,
        $currency,
        [
            'webhook_event_id' => $eventId,
            'gateway' => $gatewayCode,
            'headers' => array_intersect_key($headers, array_flip(['stripe-signature', 'x-razorpay-signature', 'x-webhook-signature', 'x-verify']))
        ]
    );

    if ($eventId) {
        $insertEv = $pdo->prepare("
            INSERT IGNORE INTO payment_webhook_events (gateway, event_id, transaction_id, payload, status, created_at)
            VALUES (:gw, :ev, :tx, :payload, 'processed', NOW())
        ");
        $insertEv->execute([
            ':gw' => $gatewayCode,
            ':ev' => $eventId,
            ':tx' => $payment['transaction_id'],
            ':payload' => substr($rawPayload, 0, 5000)
        ]);
    }

    // If client was redirected to callback in browser, redirect to status page
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/html') && empty($rawPayload)) {
        header("Location: /user/payment-status.php?tx=" . urlencode($payment['transaction_id']));
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Payment processed and wallet credited successfully']);
    exit;
}

// If payment failed or canceled
if (($verification['status'] ?? '') === 'failed') {
    $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = :id AND status = 'pending'")
        ->execute([':id' => $payment['id']]);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'acknowledged', 'message' => 'Payment status marked as failed']);
    exit;
}

// Pending or other intermediate state
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'pending', 'message' => 'Payment is awaiting confirmation']);
