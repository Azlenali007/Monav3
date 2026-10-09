<?php
/**
 * Mona SMM Panel v2 - Mass Order Placement
 * Batch processing with strict balance verification and non-deduction on failed lines
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Mass Order | " . SITE_NAME;

$results = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $rawOrders = trim((string)($_POST['orders'] ?? ''));

    if (empty($rawOrders)) {
        $error = "Please enter orders in the mass order box.";
    } else {
        $lines = explode("\n", str_replace("\r", "", $rawOrders));
        $servicesStmt = $pdo->query("SELECT * FROM services WHERE status = 1");
        $serviceMap = [];
        while ($row = $servicesStmt->fetch()) {
            $serviceMap[$row['id']] = $row;
        }

        $processedCount = 0;
        $failedCount = 0;

        foreach ($lines as $lineIndex => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) {
                $results[] = [
                    'line' => $line,
                    'status' => 'error',
                    'message' => 'Invalid format. Required: service_id | quantity | link'
                ];
                $failedCount++;
                continue;
            }

            $serviceId = (int)$parts[0];
            $quantity = (int)$parts[1];
            $link = $parts[2];

            if (!isset($serviceMap[$serviceId])) {
                $results[] = [
                    'line' => $line,
                    'status' => 'error',
                    'message' => "Service #{$serviceId} not found or inactive"
                ];
                $failedCount++;
                continue;
            }

            $service = $serviceMap[$serviceId];
            if ($quantity < (int)$service['min_quantity'] || $quantity > (int)$service['max_quantity']) {
                $results[] = [
                    'line' => $line,
                    'status' => 'error',
                    'message' => "Quantity {$quantity} outside limits ({$service['min_quantity']} - {$service['max_quantity']})"
                ];
                $failedCount++;
                continue;
            }

            // Server-side charge computation
            $rate = (float)$service['rate'];
            $charge = round(($rate * $quantity) / 1000, 4);

            try {
                $pdo->beginTransaction();

                // Lock user balance
                $lock = $pdo->prepare("SELECT balance FROM users WHERE id = :id FOR UPDATE");
                $lock->execute([':id' => $user['id']]);
                $bal = (float)$lock->fetchColumn();

                if ($bal < $charge) {
                    $pdo->rollBack();
                    $results[] = [
                        'line' => $line,
                        'status' => 'error',
                        'message' => "Insufficient balance (Charge: \${$charge}, Balance: \${$bal})"
                    ];
                    $failedCount++;
                    continue;
                }

                // Deduct balance
                $pdo->prepare("UPDATE users SET balance = balance - :chg, spent = spent + :chg WHERE id = :id")
                    ->execute([':chg' => $charge, ':id' => $user['id']]);

                // Insert order
                $pdo->prepare("
                    INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, status, created_at)
                    VALUES (:uid, :sid, :pid, :link, :qty, :chg, 'pending', NOW())
                ")->execute([
                    ':uid' => $user['id'],
                    ':sid' => $serviceId,
                    ':pid' => $service['provider_id'] ?: null,
                    ':link' => $link,
                    ':qty' => $quantity,
                    ':chg' => $charge
                ]);
                $orderId = (int)$pdo->lastInsertId();

                // Log transaction
                $pdo->prepare("
                    INSERT INTO transactions (user_id, type, amount, currency, description, reference_id, created_at)
                    VALUES (:uid, 'debit', :amt, 'USD', :desc, :ref, NOW())
                ")->execute([
                    ':uid' => $user['id'],
                    ':amt' => $charge,
                    ':desc' => "Mass Order #{$orderId}: " . substr($service['name'], 0, 30),
                    ':ref' => "order_{$orderId}"
                ]);

                $pdo->commit();

                // Dispatch to provider if configured
                if (!empty($service['provider_id']) && !empty($service['provider_service_id'])) {
                    $provStmt = $pdo->prepare("SELECT * FROM providers WHERE id = :pid LIMIT 1");
                    $provStmt->execute([':pid' => $service['provider_id']]);
                    $provider = $provStmt->fetch();
                    if ($provider) {
                        $apiRes = callProviderApi($provider, [
                            'action' => 'add',
                            'service' => $service['provider_service_id'],
                            'link' => $link,
                            'quantity' => $quantity
                        ]);
                        if (!empty($apiRes['order'])) {
                            $pdo->prepare("UPDATE orders SET provider_order_id = :p_oid, status = 'processing' WHERE id = :id")
                                ->execute([':p_oid' => (string)$apiRes['order'], ':id' => $orderId]);
                        }
                    }
                }

                $results[] = [
                    'line' => $line,
                    'status' => 'success',
                    'message' => "Order #{$orderId} created successfully (\${$charge})"
                ];
                $processedCount++;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results[] = [
                    'line' => $line,
                    'status' => 'error',
                    'message' => "System error processing line: " . $e->getMessage()
                ];
                $failedCount++;
            }
        }

        $user = currentUser(); // Refresh balance
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Mass Order</h1>
            <p class="text-slate-400 text-sm mt-1">Submit multiple orders in bulk. Each order will be validated and processed independently.</p>
        </div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-2 flex items-center space-x-3">
            <span class="text-xs text-slate-400">Available:</span>
            <span class="text-sm font-bold text-emerald-400"><?= formatCurrency((float)$user['balance'], $user['currency']) ?></span>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl space-y-6">
        <div class="bg-slate-950/60 border border-slate-800 rounded-xl p-4 text-xs text-slate-400 space-y-1">
            <span class="font-bold text-slate-200">Formatting Instructions:</span>
            <p>One order per line. Format: <code class="text-blue-400 font-mono">service_id | quantity | link</code></p>
            <p class="font-mono text-slate-500">Example:<br>102 | 1000 | https://instagram.com/user1<br>105 | 500 | https://youtube.com/watch?v=xyz</p>
        </div>

        <form method="POST" action="/user/mass-order.php" class="space-y-4">
            <?= getCsrfInput() ?>

            <div>
                <textarea name="orders" rows="8" required placeholder="102 | 1000 | https://example.com/link"
                          class="w-full bg-slate-950 border border-slate-800 rounded-xl p-4 text-white font-mono text-sm focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-6 rounded-xl transition shadow-lg shadow-blue-500/20">
                Submit Batch Orders
            </button>
        </form>
    </div>

    <?php if (!empty($results)): ?>
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-bold text-white">Batch Execution Results</h2>
        <div class="space-y-2">
            <?php foreach ($results as $r): ?>
            <div class="p-3 rounded-xl text-xs flex items-center justify-between border <?= $r['status'] === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' : 'bg-rose-500/10 border-rose-500/20 text-rose-300' ?>">
                <span class="font-mono truncate max-w-md"><?= htmlspecialchars($r['line']) ?></span>
                <span class="font-semibold"><?= htmlspecialchars($r['message']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
