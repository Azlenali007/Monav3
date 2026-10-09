<?php
/**
 * Mona SMM Panel v2 - Standard SMM Reseller API v2
 * Compliant with standard SMM API specifications
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$apiKey = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));

if (empty($apiKey)) {
    echo json_encode(['error' => 'API key is required']);
    exit;
}

// Authenticate user via api_key
$userStmt = $pdo->prepare("SELECT id, username, balance, currency, custom_rates, status FROM users WHERE api_key = :k LIMIT 1");
$userStmt->execute([':k' => $apiKey]);
$user = $userStmt->fetch();

if (!$user || $user['status'] !== 'active') {
    echo json_encode(['error' => 'Invalid API key or inactive account']);
    exit;
}

switch ($action) {
    case 'balance':
        echo json_encode([
            'status' => 'success',
            'balance' => number_format((float)$user['balance'], 4, '.', ''),
            'currency' => $user['currency'] ?? 'USD'
        ]);
        break;

    case 'services':
        $stmt = $pdo->query("
            SELECT s.id as service, s.name, s.type, c.name as category, s.rate, s.min_quantity as min, s.max_quantity as max, s.drip_feed 
            FROM services s 
            LEFT JOIN categories c ON s.category_id = c.id 
            WHERE s.status = 1 
            ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC
        ");
        $services = $stmt->fetchAll();

        // Apply custom rates if present
        $customRates = !empty($user['custom_rates']) ? json_decode((string)$user['custom_rates'], true) : [];
        if (is_array($customRates)) {
            foreach ($services as &$s) {
                if (isset($customRates[$s['service']])) {
                    $s['rate'] = number_format((float)$customRates[$s['service']], 4, '.', '');
                }
            }
        }

        echo json_encode($services);
        break;

    case 'add':
        $serviceId = (int)($_POST['service'] ?? 0);
        $link = trim((string)($_POST['link'] ?? ''));
        $quantity = (int)($_POST['quantity'] ?? 0);

        if ($serviceId <= 0 || empty($link) || $quantity <= 0) {
            echo json_encode(['error' => 'Parameters service, link, and quantity are required']);
            exit;
        }

        $sStmt = $pdo->prepare("SELECT * FROM services WHERE id = :id AND status = 1 LIMIT 1");
        $sStmt->execute([':id' => $serviceId]);
        $service = $sStmt->fetch();

        if (!$service) {
            echo json_encode(['error' => 'Service not found or inactive']);
            exit;
        }

        $min = (int)$service['min_quantity'];
        $max = (int)$service['max_quantity'];
        if ($quantity < $min || $quantity > $max) {
            echo json_encode(['error' => "Quantity must be between {$min} and {$max}"]);
            exit;
        }

        // Calculate rate
        $rate = (float)$service['rate'];
        $customRates = !empty($user['custom_rates']) ? json_decode((string)$user['custom_rates'], true) : [];
        if (is_array($customRates) && isset($customRates[$serviceId])) {
            $rate = (float)$customRates[$serviceId];
        }

        $charge = round(($rate * $quantity) / 1000, 4);

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare("SELECT balance FROM users WHERE id = :id FOR UPDATE");
            $lock->execute([':id' => $user['id']]);
            $currentBalance = (float)$lock->fetchColumn();

            if ($currentBalance < $charge) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Not enough balance']);
                exit;
            }

            // Deduct
            $pdo->prepare("UPDATE users SET balance = balance - :chg, spent = spent + :chg WHERE id = :id")
                ->execute([':chg' => $charge, ':id' => $user['id']]);

            // Create Order
            $orderStmt = $pdo->prepare("
                INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, status, created_at)
                VALUES (:uid, :sid, :pid, :link, :qty, :chg, 'pending', NOW())
            ");
            $orderStmt->execute([
                ':uid' => $user['id'],
                ':sid' => $serviceId,
                ':pid' => $service['provider_id'] ?: null,
                ':link' => $link,
                ':qty' => $quantity,
                ':chg' => $charge
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO transactions (user_id, type, amount, currency, description, reference_id, created_at)
                VALUES (:uid, 'debit', :amt, 'USD', :desc, :ref, NOW())
            ")->execute([
                ':uid' => $user['id'],
                ':amt' => $charge,
                ':desc' => "API Order #{$orderId}: " . substr($service['name'], 0, 30),
                ':ref' => "api_order_{$orderId}"
            ]);

            $pdo->commit();

            // Forward to upstream provider
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

            echo json_encode(['order' => $orderId]);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("API v2 add order error: " . $e->getMessage());
            echo json_encode(['error' => 'Internal server error processing order']);
            exit;
        }
        break;

    case 'status':
        $singleOrder = (int)($_POST['order'] ?? 0);
        $multiOrders = trim((string)($_POST['orders'] ?? ''));

        if ($singleOrder > 0) {
            $stmt = $pdo->prepare("SELECT charge, start_count, status, remains, currency FROM orders WHERE id = :id AND user_id = :uid LIMIT 1");
            $stmt->execute([':id' => $singleOrder, ':uid' => $user['id']]);
            $o = $stmt->fetch();

            if (!$o) {
                echo json_encode(['error' => 'Incorrect order ID']);
            } else {
                echo json_encode([
                    'charge' => number_format((float)$o['charge'], 4, '.', ''),
                    'start_count' => (string)$o['start_count'],
                    'status' => ucwords(str_replace('_', ' ', $o['status'])),
                    'remains' => (string)$o['remains'],
                    'currency' => $o['currency'] ?? 'USD'
                ]);
            }
        } elseif (!empty($multiOrders)) {
            $ids = array_filter(array_map('intval', explode(',', $multiOrders)));
            if (empty($ids)) {
                echo json_encode(['error' => 'No valid order IDs provided']);
                exit;
            }

            $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$user['id']], $ids);

            $stmt = $pdo->prepare("SELECT id, charge, start_count, status, remains, currency FROM orders WHERE user_id = ? AND id IN ({$inPlaceholders})");
            $stmt->execute($params);
            $found = $stmt->fetchAll();

            $result = [];
            foreach ($ids as $id) {
                $result[$id] = ['error' => 'Incorrect order ID'];
            }
            foreach ($found as $row) {
                $result[$row['id']] = [
                    'charge' => number_format((float)$row['charge'], 4, '.', ''),
                    'start_count' => (string)$row['start_count'],
                    'status' => ucwords(str_replace('_', ' ', $row['status'])),
                    'remains' => (string)$row['remains'],
                    'currency' => $row['currency'] ?? 'USD'
                ];
            }
            echo json_encode($result);
        } else {
            echo json_encode(['error' => 'Missing order or orders parameter']);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid or unsupported action']);
        break;
}
