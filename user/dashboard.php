<?php
/**
 * Mona SMM Panel v2 - Authenticated User Dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();

// Order statistics for user
$statStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status IN ('pending', 'processing', 'in_progress') THEN 1 ELSE 0 END) as active_orders,
        SUM(CASE WHEN status IN ('canceled', 'refunded') THEN 1 ELSE 0 END) as refunded_orders
    FROM orders 
    WHERE user_id = :uid
");
$statStmt->execute([':uid' => $user['id']]);
$stats = $statStmt->fetch() ?: [];

// Fetch non-dismissed announcements
$announcements = [];
try {
    $annStmt = $pdo->prepare("
        SELECT a.* 
        FROM announcements a 
        LEFT JOIN user_announcement_dismissals d ON a.id = d.announcement_id AND d.user_id = :uid 
        WHERE a.status = 1 AND d.id IS NULL 
        ORDER BY a.id DESC LIMIT 3
    ");
    $annStmt->execute([':uid' => $user['id']]);
    $announcements = $annStmt->fetchAll();
} catch (Exception $e) {
    // Announcements table might be pending migration
}

// Fetch 5 most recent orders
$recentOrdersStmt = $pdo->prepare("
    SELECT o.*, s.name as service_name 
    FROM orders o 
    LEFT JOIN services s ON o.service_id = s.id 
    WHERE o.user_id = :uid 
    ORDER BY o.id DESC LIMIT 5
");
$recentOrdersStmt->execute([':uid' => $user['id']]);
$recentOrders = $recentOrdersStmt->fetchAll();

$pageTitle = "Dashboard | " . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <!-- Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-900/40 via-indigo-900/20 to-slate-900 border border-blue-500/20 rounded-2xl p-6 sm:p-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                Hello, <?= htmlspecialchars($user['username']) ?>! 👋
            </h1>
            <p class="text-slate-300 text-sm mt-1">Welcome to your dashboard. Place new orders, track status, or deposit funds seamlessly.</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="/user/new-order.php" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-5 py-2.5 rounded-xl transition shadow-lg shadow-blue-500/20 text-sm">
                + New Order
            </a>
            <a href="/user/add-funds.php" class="bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-5 py-2.5 rounded-xl transition shadow-lg shadow-emerald-500/20 text-sm">
                + Add Funds
            </a>
        </div>
    </div>

    <!-- Active Announcements -->
    <?php foreach ($announcements as $ann): ?>
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex items-start justify-between gap-4" x-data="{ dismissed: false }" x-show="!dismissed">
        <div class="flex items-start space-x-3">
            <span class="p-2 rounded-xl text-xs font-bold uppercase <?= $ann['type'] === 'danger' ? 'bg-rose-500/20 text-rose-400' : 'bg-blue-500/20 text-blue-400' ?>">
                <?= htmlspecialchars($ann['type']) ?>
            </span>
            <div>
                <h3 class="font-bold text-white text-sm"><?= htmlspecialchars($ann['title']) ?></h3>
                <p class="text-xs text-slate-400 mt-0.5 leading-relaxed"><?= nl2br(htmlspecialchars($ann['content'])) ?></p>
            </div>
        </div>
        <button @click="dismissed = true; fetch('/user/api/dismiss-announcement.php?id=<?= $ann['id'] ?>')" class="text-slate-500 hover:text-white text-xs">
            &times; Dismiss
        </button>
    </div>
    <?php endforeach; ?>

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Account Balance</span>
            <div class="text-2xl font-black text-emerald-400"><?= formatCurrency((float)$user['balance'], $user['currency']) ?></div>
            <div class="text-xs text-slate-500"><a href="/user/add-funds.php" class="text-blue-400 hover:underline">Deposit funds &rarr;</a></div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Spent</span>
            <div class="text-2xl font-black text-white"><?= formatCurrency((float)$user['spent'], $user['currency']) ?></div>
            <div class="text-xs text-slate-500">Across all lifetime orders</div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Orders</span>
            <div class="text-2xl font-black text-blue-400"><?= number_format((int)($stats['total_orders'] ?? 0)) ?></div>
            <div class="text-xs text-slate-500"><?= number_format((int)($stats['completed_orders'] ?? 0)) ?> successfully completed</div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Active In-Flight</span>
            <div class="text-2xl font-black text-amber-400"><?= number_format((int)($stats['active_orders'] ?? 0)) ?></div>
            <div class="text-xs text-slate-500">Processing or in-progress</div>
        </div>
    </div>

    <!-- Recent Orders Section -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-white">Recent Orders</h2>
            <a href="/user/orders.php" class="text-xs font-semibold text-blue-400 hover:underline">View All Orders &rarr;</a>
        </div>

        <?php if (empty($recentOrders)): ?>
            <div class="py-8 text-center text-slate-500 text-sm">
                No orders placed yet. <a href="/user/new-order.php" class="text-blue-400 font-semibold hover:underline">Place your first order</a>!
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="pb-3">ID</th>
                            <th class="pb-3">Service</th>
                            <th class="pb-3">Link</th>
                            <th class="pb-3">Quantity</th>
                            <th class="pb-3">Charge</th>
                            <th class="pb-3">Status</th>
                            <th class="pb-3">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td class="py-3 font-mono text-xs text-slate-400">#<?= $o['id'] ?></td>
                            <td class="py-3 font-medium text-white max-w-xs truncate"><?= htmlspecialchars((string)($o['service_name'] ?? 'Service #' . $o['service_id'])) ?></td>
                            <td class="py-3 font-mono text-xs text-slate-400 max-w-xs truncate"><?= htmlspecialchars($o['link']) ?></td>
                            <td class="py-3 font-mono text-xs text-slate-200"><?= number_format($o['quantity']) ?></td>
                            <td class="py-3 font-semibold text-emerald-400"><?= formatCurrency((float)$o['charge'], 'USD') ?></td>
                            <td class="py-3">
                                <?php if ($o['status'] === 'completed'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Completed</span>
                                <?php elseif (in_array($o['status'], ['pending', 'processing', 'in_progress'])): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-500/20 text-blue-400 capitalize"><?= str_replace('_', ' ', $o['status']) ?></span>
                                <?php elseif ($o['status'] === 'partial'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300">Partial</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-400 capitalize"><?= $o['status'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-xs text-slate-400"><?= date('M d, H:i', strtotime($o['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
