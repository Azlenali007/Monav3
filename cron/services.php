<?php
/**
 * Mona SMM Panel v2 - Provider Services Rate Synchronization
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$providers = $pdo->query("SELECT * FROM providers WHERE status = 1")->fetchAll();

foreach ($providers as $prov) {
    echo "Syncing provider: {$prov['name']}...\n";
    $res = callProviderApi($prov, ['action' => 'services']);

    if (!is_array($res) || !empty($res['error'])) {
        echo "Failed to sync {$prov['name']}: " . ($res['error'] ?? 'Invalid response') . "\n";
        continue;
    }

    $remoteMap = [];
    foreach ($res as $item) {
        if (!empty($item['service'])) {
            $remoteMap[(string)$item['service']] = $item;
        }
    }

    // Query local services linked to this provider
    $stmt = $pdo->prepare("SELECT id, provider_service_id, rate, original_rate FROM services WHERE provider_id = :pid");
    $stmt->execute([':pid' => $prov['id']]);
    $localServices = $stmt->fetchAll();

    foreach ($localServices as $s) {
        $pSid = (string)$s['provider_service_id'];
        if (isset($remoteMap[$pSid])) {
            $remoteRate = (float)($remoteMap[$pSid]['rate'] ?? 0);
            if ($remoteRate > 0) {
                $pdo->prepare("UPDATE services SET original_rate = :orate WHERE id = :id")
                    ->execute([':orate' => $remoteRate, ':id' => $s['id']]);
            }
        }
    }
}

echo "Services sync complete.\n";
