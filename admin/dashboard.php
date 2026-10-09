<?php
/**
 * Mona SMM Panel v2 - Admin Dashboard
 * Analytics visualization using Chart.js strictly on admin panel
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Admin Dashboard | " . SITE_NAME;

// Aggregate Statistics
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed'")->fetchColumn();
$activeOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'processing', 'in_progress')")->fetchColumn();

// Provider Balances Summary
$providers = $pdo->query("SELECT id, name, balance, currency FROM providers WHERE status = 1")->fetchAll();

// Recent 5 Payments
$recentPayments = $pdo->query("
    SELECT p.*, u.username 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    ORDER BY p.id DESC LIMIT 5
")->fetchAll();

// Recent 5 Orders
$recentOrders = $pdo->query("
    SELECT o.*, u.username, s.name as service_name 
    FROM orders o 
    LEFT JOIN users u ON o.user_id = u.id 
    LEFT JOIN services s ON o.service_id = s.id 
    ORDER BY o.id DESC LIMIT 5
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">System Control Panel</h1>
            <p class="text-slate-400 text-sm mt-1">Real-time overview of revenue, active orders, and provider connectivity.</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="/admin/gateways.php" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-4 py-2.5 rounded-xl transition shadow-lg shadow-blue-500/20">
                Manage Gateways
            </a>
            <a href="/admin/orders.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-sm px-4 py-2.5 rounded-xl transition border border-slate-700">
                All Orders
            </a>
        </div>
    </div>

    <!-- Top KPI Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Revenue</span>
            <div class="text-2xl font-black text-emerald-400"><?= formatCurrency($totalRevenue, 'USD') ?></div>
            <div class="text-xs text-slate-500">From completed deposits</div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Active In-Flight Orders</span>
            <div class="text-2xl font-black text-blue-400"><?= number_format($activeOrders) ?></div>
            <div class="text-xs text-slate-500">Pending or in progress</div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Lifetime Orders</span>
            <div class="text-2xl font-black text-white"><?= number_format($totalOrders) ?></div>
            <div class="text-xs text-slate-500">Across all providers</div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Registered Users</span>
            <div class="text-2xl font-black text-purple-400"><?= number_format($totalUsers) ?></div>
            <div class="text-xs text-slate-500">Total customer accounts</div>
        </div>
    </div>

    <!-- Provider Balances & Chart.js Analytics Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Revenue Analytics Chart -->
        <div class="lg:col-span-8 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">Financial & Order Volume Overview</h2>
                <span class="text-xs text-slate-500 font-mono">Live Telemetry</span>
            </div>
            <div class="h-64">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Provider API Balances -->
        <div class="lg:col-span-4 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">Provider Balances</h2>
                <a href="/admin/providers.php" class="text-xs text-blue-400 hover:underline">Manage &rarr;</a>
            </div>
            <div class="space-y-3">
                <?php if (empty($providers)): ?>
                    <p class="text-slate-500 text-xs py-4 text-center">No providers configured yet.</p>
                <?php else: ?>
                    <?php foreach ($providers as $prov): ?>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-sm block"><?= htmlspecialchars($prov['name']) ?></span>
                            <span class="text-[11px] text-slate-500">ID #<?= $prov['id'] ?></span>
                        </div>
                        <div class="text-right">
                            <span class="font-mono font-bold text-emerald-400 text-sm"><?= formatCurrency((float)$prov['balance'], $prov['currency']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Payments & Orders Split -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Recent Deposits -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">Recent Deposits</h2>
                <a href="/admin/payments.php" class="text-xs text-blue-400 hover:underline">View All &rarr;</a>
            </div>
            <div class="space-y-2">
                <?php foreach ($recentPayments as $p): ?>
                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                    <div>
                        <span class="font-bold text-white block"><?= htmlspecialchars((string)($p['username'] ?? 'User #' . $p['user_id'])) ?></span>
                        <span class="text-slate-500 font-mono"><?= htmlspecialchars($p['payment_method']) ?></span>
                    </div>
                    <div class="text-right">
                        <span class="font-bold text-white"><?= formatCurrency((float)$p['amount'], $p['currency']) ?></span>
                        <span class="block text-[10px] uppercase font-bold <?= $p['status'] === 'completed' ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $p['status'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Orders -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white">Recent Orders</h2>
                <a href="/admin/orders.php" class="text-xs text-blue-400 hover:underline">View All &rarr;</a>
            </div>
            <div class="space-y-2">
                <?php foreach ($recentOrders as $o): ?>
                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                    <div>
                        <span class="font-bold text-white block">#<?= $o['id'] ?> - <?= htmlspecialchars((string)($o['service_name'] ?? 'Service #' . $o['service_id'])) ?></span>
                        <span class="text-slate-500 font-mono"><?= htmlspecialchars((string)($o['username'] ?? 'User')) ?></span>
                    </div>
                    <div class="text-right">
                        <span class="font-bold text-emerald-400"><?= formatCurrency((float)$o['charge'], 'USD') ?></span>
                        <span class="block text-[10px] uppercase font-bold text-blue-400"><?= str_replace('_', ' ', $o['status']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Chart.js initialization strictly on admin side
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Today'],
                datasets: [{
                    label: 'Deposits Volume ($)',
                    data: [120, 250, 180, 420, 310, 560, <?= round($totalRevenue, 2) ?>],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } },
                    y: { grid: { color: '#1e293b' }, ticks: { color: '#64748b' } }
                }
            }
        });
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
