<?php
/**
 * Mona SMM Panel v2 - User Order History
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "My Orders | " . SITE_NAME;

$statusFilter = cleanInput($_GET['status'] ?? '');
$search = cleanInput($_GET['q'] ?? '');

$where = ["o.user_id = :uid"];
$params = [':uid' => $user['id']];

if ($statusFilter !== '') {
    $where[] = "o.status = :status";
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(o.link LIKE :search OR s.name LIKE :search2 OR o.id = :searchId)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':searchId'] = (int)$search;
}

$whereSql = implode(" AND ", $where);

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN services s ON o.service_id = s.id WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Fetch orders
$stmt = $pdo->prepare("
    SELECT o.*, s.name as service_name 
    FROM orders o 
    LEFT JOIN services s ON o.service_id = s.id 
    WHERE {$whereSql} 
    ORDER BY o.id DESC 
    LIMIT 50
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Order History</h1>
            <p class="text-slate-400 text-sm mt-1">Review live delivery progress, start counts, and remains.</p>
        </div>
        <a href="/user/new-order.php" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-4 py-2.5 rounded-xl transition">
            + New Order
        </a>
    </div>

    <!-- Filter Tabs -->
    <div class="flex items-center space-x-2 overflow-x-auto pb-2">
        <?php 
            $tabs = [
                '' => 'All',
                'pending' => 'Pending',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'partial' => 'Partial',
                'canceled' => 'Canceled'
            ];
            foreach ($tabs as $key => $label):
        ?>
        <a href="/user/orders.php?status=<?= $key ?>" 
           class="px-4 py-2 rounded-xl text-xs font-semibold uppercase tracking-wider transition border border-slate-800 flex-shrink-0 <?= $statusFilter === $key ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:text-white' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Orders Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Service</th>
                        <th class="p-4">Link</th>
                        <th class="p-4">Quantity</th>
                        <th class="p-4">Charge</th>
                        <th class="p-4">Start / Remains</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8" class="p-8 text-center text-slate-500">No orders found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $o['id'] ?></td>
                            <td class="p-4 font-medium text-white max-w-xs truncate"><?= htmlspecialchars((string)($o['service_name'] ?? 'Service #' . $o['service_id'])) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400 max-w-xs truncate">
                                <a href="<?= htmlspecialchars($o['link']) ?>" target="_blank" rel="noopener" class="text-blue-400 hover:underline"><?= htmlspecialchars($o['link']) ?></a>
                            </td>
                            <td class="p-4 font-mono text-xs text-slate-200"><?= number_format($o['quantity']) ?></td>
                            <td class="p-4 font-semibold text-emerald-400"><?= formatCurrency((float)$o['charge'], 'USD') ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400"><?= number_format($o['start_count']) ?> / <?= number_format($o['remains']) ?></td>
                            <td class="p-4">
                                <?php if ($o['status'] === 'completed'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Completed</span>
                                <?php elseif (in_array($o['status'], ['pending', 'processing', 'in_progress'])): ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-400 capitalize"><?= str_replace('_', ' ', $o['status']) ?></span>
                                <?php elseif ($o['status'] === 'partial'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300">Partial</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-400 capitalize"><?= $o['status'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-xs text-slate-400"><?= date('M d, H:i', strtotime($o['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
