<?php
/**
 * Mona SMM Panel v2 - Admin Order Management
 * Status overrides, provider resends, and safe administrative refunds
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Orders Management | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($action === 'change_status' && $orderId > 0) {
        $newStatus = cleanInput($_POST['new_status'] ?? '');
        $allowed = ['pending', 'processing', 'in_progress', 'completed', 'partial', 'canceled', 'refunded'];

        if (in_array($newStatus, $allowed)) {
            $pdo->prepare("UPDATE orders SET status = :st WHERE id = :id")->execute([':st' => $newStatus, ':id' => $orderId]);
            $msg = "Order #{$orderId} status updated to " . ucfirst($newStatus);
        }
    } elseif ($action === 'cancel_and_refund' && $orderId > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();

            if ($order && !in_array($order['status'], ['canceled', 'refunded'])) {
                $charge = (float)$order['charge'];
                $alreadyRefunded = (float)($order['refunded_amount'] ?? 0);
                $refundDue = max(0.0, $charge - $alreadyRefunded);

                if ($refundDue > 0) {
                    $pdo->prepare("UPDATE users SET balance = balance + :ref WHERE id = :uid")
                        ->execute([':ref' => $refundDue, ':uid' => $order['user_id']]);

                    $pdo->prepare("UPDATE orders SET status = 'canceled', refunded_amount = refunded_amount + :ref WHERE id = :id")
                        ->execute([':ref' => $refundDue, ':id' => $orderId]);

                    $pdo->prepare("
                        INSERT INTO transactions (user_id, type, amount, currency, description, reference_id, created_at)
                        VALUES (:uid, 'refund', :amt, 'USD', :desc, :ref, NOW())
                    ")->execute([
                        ':uid' => $order['user_id'],
                        ':amt' => $refundDue,
                        ':desc' => "Admin cancellation refund for order #{$orderId}",
                        ':ref' => "admin_refund_{$orderId}"
                    ]);

                    $msg = "Order #{$orderId} canceled and \${$refundDue} refunded to user wallet.";
                } else {
                    $pdo->prepare("UPDATE orders SET status = 'canceled' WHERE id = :id")->execute([':id' => $orderId]);
                    $msg = "Order #{$orderId} marked as canceled (no refund due).";
                }
            } else {
                $err = "Order was already canceled or not found.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $err = "Failed to process refund: " . $e->getMessage();
        }
    }
}

// Filters
$statusFilter = cleanInput($_GET['status'] ?? '');
$search = cleanInput($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($statusFilter !== '') {
    $where[] = "o.status = :status";
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(o.link LIKE :search OR u.username LIKE :search2 OR o.id = :searchId OR o.provider_order_id LIKE :search3)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':searchId'] = (int)$search;
    $params[':search3'] = "%{$search}%";
}

$whereSql = implode(" AND ", $where);

$stmt = $pdo->prepare("
    SELECT o.*, u.username, s.name as service_name, p.name as provider_name 
    FROM orders o 
    LEFT JOIN users u ON o.user_id = u.id 
    LEFT JOIN services s ON o.service_id = s.id 
    LEFT JOIN providers p ON o.provider_id = p.id 
    WHERE {$whereSql} 
    ORDER BY o.id DESC 
    LIMIT 50
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Order Management</h1>
            <p class="text-slate-400 text-sm mt-1">Review live provider dispatch, update statuses, or execute refunds.</p>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($err): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($err) ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
        <form method="GET" action="/admin/orders.php" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <input type="text" name="q" placeholder="Search Order ID, Link, User..." value="<?= htmlspecialchars($search) ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <select name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="processing" <?= $statusFilter === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="canceled" <?= $statusFilter === 'canceled' ? 'selected' : '' ?>>Canceled</option>
                </select>
            </div>
            <div>
                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2 px-4 rounded-xl transition text-sm">
                    Filter Orders
                </button>
            </div>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">User</th>
                        <th class="p-4">Service</th>
                        <th class="p-4">Link</th>
                        <th class="p-4">Quantity</th>
                        <th class="p-4">Charge</th>
                        <th class="p-4">Provider Order ID</th>
                        <th class="p-4">Status</th>
                        <th class="p-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="9" class="p-8 text-center text-slate-500">No orders matching criteria.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $o['id'] ?></td>
                            <td class="p-4 font-semibold text-white"><?= htmlspecialchars((string)($o['username'] ?? 'User #' . $o['user_id'])) ?></td>
                            <td class="p-4 max-w-xs truncate text-slate-200"><?= htmlspecialchars((string)($o['service_name'] ?? 'Service #' . $o['service_id'])) ?></td>
                            <td class="p-4 font-mono text-xs text-blue-400 max-w-xs truncate">
                                <a href="<?= htmlspecialchars($o['link']) ?>" target="_blank" rel="noopener" class="hover:underline"><?= htmlspecialchars($o['link']) ?></a>
                            </td>
                            <td class="p-4 font-mono text-xs text-slate-200"><?= number_format($o['quantity']) ?></td>
                            <td class="p-4 font-bold text-emerald-400"><?= formatCurrency((float)$o['charge'], 'USD') ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400"><?= htmlspecialchars((string)($o['provider_order_id'] ?? '-')) ?></td>
                            <td class="p-4">
                                <form method="POST" action="/admin/orders.php" class="inline-block">
                                    <?= getCsrfInput() ?>
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                    <select name="new_status" onchange="this.form.submit()" 
                                            class="bg-slate-950 border border-slate-800 rounded-lg px-2 py-1 text-xs text-white focus:outline-none focus:border-blue-500 font-semibold uppercase">
                                        <?php foreach (['pending', 'processing', 'in_progress', 'completed', 'partial', 'canceled', 'refunded'] as $st): ?>
                                            <option value="<?= $st ?>" <?= $o['status'] === $st ? 'selected' : '' ?>><?= str_replace('_', ' ', $st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="p-4 text-right">
                                <?php if (!in_array($o['status'], ['canceled', 'refunded'])): ?>
                                <form method="POST" action="/admin/orders.php" onsubmit="return confirm('Cancel this order and refund user wallet?');" class="inline-block">
                                    <?= getCsrfInput() ?>
                                    <input type="hidden" name="action" value="cancel_and_refund">
                                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                    <button type="submit" class="text-rose-400 hover:text-rose-300 text-xs font-semibold hover:underline">
                                        Cancel & Refund
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="text-xs text-slate-600">Settled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
