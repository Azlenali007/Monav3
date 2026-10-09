<?php
/**
 * Mona SMM Panel v2 - Drip-Feed Orders Overview
 * Fixes column name alignment to runs_completed
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Drip-Feed Orders | " . SITE_NAME;

// Select drip-feed orders using standardized column runs_completed
$stmt = $pdo->prepare("
    SELECT d.id, d.service_id, d.link, d.total_quantity, d.quantity_per_run, d.runs, d.interval_minutes, 
           d.runs_completed, d.total_charge, d.status, d.created_at, s.name as service_name
    FROM drip_feed_orders d
    LEFT JOIN services s ON d.service_id = s.id
    WHERE d.user_id = :uid
    ORDER BY d.id DESC
");
$stmt->execute([':uid' => $user['id']]);
$dripOrders = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Drip-Feed Orders</h1>
            <p class="text-slate-400 text-sm mt-1">Monitor the execution intervals and progress of multi-run orders.</p>
        </div>
        <a href="/user/new-order.php" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-4 py-2 rounded-xl transition">
            + Create New Drip
        </a>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Service</th>
                        <th class="p-4">Link</th>
                        <th class="p-4">Total Qty</th>
                        <th class="p-4">Runs Progress</th>
                        <th class="p-4">Interval</th>
                        <th class="p-4">Total Charge</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php if (empty($dripOrders)): ?>
                    <tr>
                        <td colspan="9" class="p-8 text-center text-slate-500">No drip-feed orders found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($dripOrders as $d): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $d['id'] ?></td>
                            <td class="p-4 font-medium text-white max-w-xs truncate"><?= htmlspecialchars((string)($d['service_name'] ?? 'Service #' . $d['service_id'])) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400 max-w-xs truncate"><?= htmlspecialchars($d['link']) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-200"><?= number_format($d['total_quantity']) ?></td>
                            <td class="p-4">
                                <div class="flex items-center space-x-2">
                                    <span class="font-mono text-xs text-blue-400 font-bold"><?= $d['runs_completed'] ?> / <?= $d['runs'] ?></span>
                                    <div class="w-16 bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-blue-500 h-1.5 rounded-full" style="width: <?= $d['runs'] > 0 ? min(100, round(($d['runs_completed'] / $d['runs']) * 100)) : 0 ?>%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 text-xs text-slate-400"><?= $d['interval_minutes'] ?> mins</td>
                            <td class="p-4 font-bold text-emerald-400"><?= formatCurrency((float)$d['total_charge'], 'USD') ?></td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $d['status'] === 'completed' ? 'bg-emerald-500/20 text-emerald-400' : ($d['status'] === 'active' ? 'bg-blue-500/20 text-blue-400' : 'bg-rose-500/20 text-rose-400') ?> capitalize">
                                    <?= htmlspecialchars($d['status']) ?>
                                </span>
                            </td>
                            <td class="p-4 text-xs text-slate-400"><?= date('M d, H:i', strtotime($d['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
