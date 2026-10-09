<?php
/**
 * Mona SMM Panel v2 - Automated Order Polling & Concurrency-Safe Refund Engine
 * Idempotent, transactionally safe, prevents duplicate and excess refunds
 */

declare(strict_types=1);

// CLI or Cron Execution Guard
if (php_sapi_name() !== 'cli' && empty($_GET['cron_key'])) {
    // Optionally allow secure web cron with key
    $cronKey = getenv('CRON_KEY') ?: 'mona_cron_secret';
    if (($_GET['key'] ?? '') !== $cronKey) {
        http_response_code(403);
        die("Direct web access forbidden. Run via CLI cron.");
    }
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// 1. Fetch pending/in-progress orders that have a provider assigned
$stmt = $pdo->query("
    SELECT o.id, o.provider_id, o.provider_order_id, p.api_url, p.api_key 
    FROM orders o 
    INNER JOIN providers p ON o.provider_id = p.id 
    WHERE o.status IN ('pending', 'processing', 'in_progress') 
      AND o.provider_order_id IS NOT NULL 
      AND o.provider_order_id != ''
    LIMIT 200
");
$orders = $stmt->fetchAll();

if (empty($orders)) {
    echo "[" . date('Y-m-d H:i:s') . "] No active provider orders to sync.\n";
    exit;
}

// Group orders by provider to batch queries where possible
$byProvider = [];
foreach ($orders as $o) {
    $byProvider[$o['provider_id']]['provider'] = [
        'api_url' => $o['api_url'],
        'api_key' => $o['api_key']
    ];
    $byProvider[$o['provider_id']]['orders'][$o['provider_order_id']] = $o['id'];
}

foreach ($byProvider as $providerId => $pData) {
    $provider = $pData['provider'];
    $orderMap = $pData['orders'];
    $remoteIds = array_keys($orderMap);

    // Call status API (single or multi-order format)
    $params = [
        'action' => 'status',
        'orders' => implode(',', $remoteIds)
    ];

    $response = callProviderApi($provider, $params);

    foreach ($remoteIds as $rId) {
        $localOrderId = $orderMap[$rId];
        $orderData = $response[$rId] ?? ($response['status'] ? $response : null);

        if (!is_array($orderData) || empty($orderData['status'])) {
            continue;
        }

        $rawStatus = ucwords(strtolower(trim((string)$orderData['status'])));
        $startCount = (int)($orderData['start_count'] ?? 0);
        $remains = (int)($orderData['remains'] ?? 0);

        try {
            $pdo->beginTransaction();

            // Lock order row exclusively for update
            $lockStmt = $pdo->prepare("
                SELECT id, user_id, charge, quantity, remains, status, refunded_amount 
                FROM orders 
                WHERE id = :id 
                FOR UPDATE
            ");
            $lockStmt->execute([':id' => $localOrderId]);
            $order = $lockStmt->fetch();

            if (!$order) {
                $pdo->rollBack();
                continue;
            }

            // IDEMPOTENCY: If order was already finalized or canceled, do not re-process refunds
            if (in_array($order['status'], ['completed', 'canceled', 'refunded'])) {
                $pdo->rollBack();
                continue;
            }

            if ($rawStatus === 'Completed') {
                $pdo->prepare("
                    UPDATE orders 
                    SET status = 'completed', start_count = :sc, remains = :rem 
                    WHERE id = :id
                ")->execute([':sc' => $startCount, ':rem' => $remains, ':id' => $localOrderId]);

            } elseif ($rawStatus === 'In Progress' || $rawStatus === 'Processing') {
                $statusSlug = ($rawStatus === 'In Progress') ? 'in_progress' : 'processing';
                $pdo->prepare("
                    UPDATE orders 
                    SET status = :st, start_count = :sc, remains = :rem 
                    WHERE id = :id
                ")->execute([':st' => $statusSlug, ':sc' => $startCount, ':rem' => $remains, ':id' => $localOrderId]);

            } elseif ($rawStatus === 'Canceled') {
                // Full Refund Calculation
                $charge = (float)$order['charge'];
                $alreadyRefunded = (float)$order['refunded_amount'];
                $refundDue = max(0.0, $charge - $alreadyRefunded);

                if ($refundDue > 0.0001) {
                    // Credit user balance
                    $pdo->prepare("UPDATE users SET balance = balance + :ref WHERE id = :uid")
                        ->execute([':ref' => $refundDue, ':uid' => $order['user_id']]);

                    // Update order
                    $pdo->prepare("
                        UPDATE orders 
                        SET status = 'canceled', refunded_amount = refunded_amount + :ref 
                        WHERE id = :id
                    ")->execute([':ref' => $refundDue, ':id' => $localOrderId]);

                    // Ledger log
                    $pdo->prepare("
                        INSERT INTO transactions (user_id, type, amount, currency, description, reference_id, created_at)
                        VALUES (:uid, 'refund', :amt, 'USD', :desc, :ref, NOW())
                    ")->execute([
                        ':uid' => $order['user_id'],
                        ':amt' => $refundDue,
                        ':desc' => "Full refund for canceled order #{$localOrderId}",
                        ':ref' => "refund_{$localOrderId}"
                    ]);

                    echo "[" . date('Y-m-d H:i:s') . "] Order #{$localOrderId} canceled. Refunded: \${$refundDue}\n";
                } else {
                    $pdo->prepare("UPDATE orders SET status = 'canceled' WHERE id = :id")
                        ->execute([':id' => $localOrderId]);
                }

            } elseif ($rawStatus === 'Partial') {
                // Concurrency-Safe Partial Refund Calculation
                $totalCharge = (float)$order['charge'];
                $totalQty = (int)$order['quantity'];
                $alreadyRefunded = (float)$order['refunded_amount'];

                if ($totalQty > 0 && $remains > 0) {
                    $unitPrice = $totalCharge / $totalQty;
                    $calculatedRefund = round($unitPrice * $remains, 4);
                    // Ensure refund never exceeds original charge minus already refunded
                    $maxPossible = max(0.0, $totalCharge - $alreadyRefunded);
                    $refundDue = min($calculatedRefund, $maxPossible);

                    if ($refundDue > 0.0001) {
                        $pdo->prepare("UPDATE users SET balance = balance + :ref WHERE id = :uid")
                            ->execute([':ref' => $refundDue, ':uid' => $order['user_id']]);

                        $pdo->prepare("
                            UPDATE orders 
                            SET status = 'partial', start_count = :sc, remains = :rem, refunded_amount = refunded_amount + :ref 
                            WHERE id = :id
                        ")->execute([
                            ':sc' => $startCount,
                            ':rem' => $remains,
                            ':ref' => $refundDue,
                            ':id' => $localOrderId
                        ]);

                        $pdo->prepare("
                            INSERT INTO transactions (user_id, type, amount, currency, description, reference_id, created_at)
                            VALUES (:uid, 'refund', :amt, 'USD', :desc, :ref, NOW())
                        ")->execute([
                            ':uid' => $order['user_id'],
                            ':amt' => $refundDue,
                            ':desc' => "Partial refund ({$remains} remains) for order #{$localOrderId}",
                            ':ref' => "partial_{$localOrderId}"
                        ]);

                        echo "[" . date('Y-m-d H:i:s') . "] Order #{$localOrderId} partial. Refunded: \${$refundDue}\n";
                    }
                } else {
                    $pdo->prepare("UPDATE orders SET status = 'partial', remains = :rem WHERE id = :id")
                        ->execute([':rem' => $remains, ':id' => $localOrderId]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Cron status error on order #{$localOrderId}: " . $e->getMessage());
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Sync finished successfully.\n";
