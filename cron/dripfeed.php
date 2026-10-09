<?php
/**
 * Mona SMM Panel v2 - Drip-Feed Scheduled Dispatcher
 * Dispatches sub-orders at configured intervals using standardized runs_completed column
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Fetch active drip-feed orders that still have remaining runs
$stmt = $pdo->query("
    SELECT d.*, s.provider_id, s.provider_service_id, s.rate, p.api_url, p.api_key
    FROM drip_feed_orders d
    JOIN services s ON d.service_id = s.id
    LEFT JOIN providers p ON s.provider_id = p.id
    WHERE d.status = 'active' 
      AND d.runs_completed < d.runs
");
$drips = $stmt->fetchAll();

foreach ($drips as $d) {
    // Check interval since last run
    $lastUpdated = strtotime($d['updated_at']);
    $minutesSinceLast = (time() - $lastUpdated) / 60;

    // First run or interval elapsed
    if ($d['runs_completed'] === 0 || $minutesSinceLast >= (int)$d['interval_minutes']) {
        $runQty = (int)$d['quantity_per_run'];
        $unitCharge = round(((float)$d['rate'] * $runQty) / 1000, 4);

        try {
            $pdo->beginTransaction();

            // Insert child order
            $orderStmt = $pdo->prepare("
                INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, status, drip_feed_id, created_at)
                VALUES (:uid, :sid, :pid, :link, :qty, :chg, 'pending', :dfid, NOW())
            ");
            $orderStmt->execute([
                ':uid' => $d['user_id'],
                ':sid' => $d['service_id'],
                ':pid' => $d['provider_id'] ?: null,
                ':link' => $d['link'],
                ':qty' => $runQty,
                ':chg' => $unitCharge,
                ':dfid' => $d['id']
            ]);
            $childOrderId = (int)$pdo->lastInsertId();

            $newRunsCompleted = $d['runs_completed'] + 1;
            $newStatus = ($newRunsCompleted >= (int)$d['runs']) ? 'completed' : 'active';

            // Update drip-feed progress
            $pdo->prepare("
                UPDATE drip_feed_orders 
                SET runs_completed = :rc, status = :st, updated_at = NOW() 
                WHERE id = :id
            ")->execute([
                ':rc' => $newRunsCompleted,
                ':st' => $newStatus,
                ':id' => $d['id']
            ]);

            $pdo->commit();

            // Forward child order to provider API if configured
            if (!empty($d['provider_id']) && !empty($d['api_url']) && !empty($d['api_key'])) {
                $provider = [
                    'api_url' => $d['api_url'],
                    'api_key' => $d['api_key']
                ];
                $apiRes = callProviderApi($provider, [
                    'action' => 'add',
                    'service' => $d['provider_service_id'],
                    'link' => $d['link'],
                    'quantity' => $runQty
                ]);

                if (!empty($apiRes['order'])) {
                    $pdo->prepare("UPDATE orders SET provider_order_id = :p_oid, status = 'processing' WHERE id = :id")
                        ->execute([':p_oid' => (string)$apiRes['order'], ':id' => $childOrderId]);
                }
            }

            echo "[" . date('Y-m-d H:i:s') . "] Drip #{$d['id']} run {$newRunsCompleted}/{$d['runs']} executed as order #{$childOrderId}.\n";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Drip execution error for #{$d['id']}: " . $e->getMessage());
        }
    }
}
