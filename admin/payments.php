<?php
/**
 * Mona SMM Panel v2 - Admin Payments Audit & Manual Approval
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways/GatewayManager.php';

use Mona\Gateways\GatewayManager;

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Payment Audits | Admin Control Panel";

$actionMsg = null;
$actionErr = null;

// Handle manual approval or rejection of deposits
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();
    $action = $_POST['action'] ?? '';
    $paymentId = (int)($_POST['payment_id'] ?? 0);

    if ($action === 'approve' && $paymentId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            $actionErr = "Payment not found.";
        } elseif ($payment['status'] !== 'pending') {
            $actionErr = "Payment is not in pending status.";
        } else {
            $credited = GatewayManager::processPaymentCredit(
                $pdo,
                $paymentId,
                $payment['gateway_ref'] ?: 'admin_manual_' . time(),
                (float)$payment['net_amount'],
                (string)$payment['currency'],
                ['approved_by_admin' => $admin['id']]
            );
            if ($credited) {
                $actionMsg = "Payment #{$paymentId} approved and wallet credited successfully.";
            } else {
                $actionErr = "Failed to credit payment.";
            }
        }
    } elseif ($action === 'reject' && $paymentId > 0) {
        $stmt = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = :id AND status = 'pending'");
        $stmt->execute([':id' => $paymentId]);
        $actionMsg = "Payment #{$paymentId} marked as rejected.";
    }
}

// Filters & Pagination
$statusFilter = cleanInput($_GET['status'] ?? '');
$methodFilter = cleanInput($_GET['method'] ?? '');
$search = cleanInput($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($statusFilter !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $statusFilter;
}
if ($methodFilter !== '') {
    $where[] = "p.payment_method = :method";
    $params[':method'] = $methodFilter;
}
if ($search !== '') {
    $where[] = "(p.transaction_id LIKE :search OR p.gateway_ref LIKE :search2 OR u.username LIKE :search3)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}

$whereSql = implode(" AND ", $where);

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p LEFT JOIN users u ON p.user_id = u.id WHERE {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

// Fetch paginated records
$stmt = $pdo->prepare("
    SELECT p.*, u.username, u.email 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    WHERE {$whereSql} 
    ORDER BY p.id DESC 
    LIMIT 50
");
$stmt->execute($params);
$payments = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Payments & Deposits Ledger</h1>
            <p class="text-slate-400 text-sm mt-1">Audit automated gateway webhooks and review pending manual transfers.</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="/admin/gateways.php" class="bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition shadow-lg shadow-blue-500/20">
                Configure Gateways
            </a>
        </div>
    </div>

    <?php if ($actionMsg): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($actionMsg) ?>
    </div>
    <?php endif; ?>

    <?php if ($actionErr): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($actionErr) ?>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
        <form method="GET" action="/admin/payments.php" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <input type="text" name="q" placeholder="Search TxID, Ref or User..." value="<?= htmlspecialchars($search) ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <select name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div>
                <select name="method" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">All Methods</option>
                    <option value="razorpay" <?= $methodFilter === 'razorpay' ? 'selected' : '' ?>>Razorpay</option>
                    <option value="cashfree" <?= $methodFilter === 'cashfree' ? 'selected' : '' ?>>Cashfree</option>
                    <option value="phonepe" <?= $methodFilter === 'phonepe' ? 'selected' : '' ?>>PhonePe</option>
                    <option value="paypal" <?= $methodFilter === 'paypal' ? 'selected' : '' ?>>PayPal</option>
                    <option value="stripe" <?= $methodFilter === 'stripe' ? 'selected' : '' ?>>Stripe</option>
                    <option value="cryptomus" <?= $methodFilter === 'cryptomus' ? 'selected' : '' ?>>Cryptomus</option>
                    <option value="nowpayments" <?= $methodFilter === 'nowpayments' ? 'selected' : '' ?>>NOWPayments</option>
                    <option value="manual" <?= $methodFilter === 'manual' ? 'selected' : '' ?>>Manual</option>
                </select>
            </div>
            <div>
                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-semibold py-2 px-4 rounded-xl transition text-sm">
                    Filter Results
                </button>
            </div>
        </form>
    </div>

    <!-- Payments Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">User</th>
                        <th class="p-4">Method</th>
                        <th class="p-4">Tx Reference</th>
                        <th class="p-4">Gateway Ref</th>
                        <th class="p-4">Amount</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Created</th>
                        <th class="p-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="p-8 text-center text-slate-500">No payment records matching the selected criteria.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $p['id'] ?></td>
                            <td class="p-4">
                                <span class="font-semibold text-white block"><?= htmlspecialchars((string)($p['username'] ?? 'User #' . $p['user_id'])) ?></span>
                                <span class="text-xs text-slate-500"><?= htmlspecialchars((string)($p['email'] ?? '')) ?></span>
                            </td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold bg-slate-800 text-slate-300 uppercase">
                                    <?= htmlspecialchars($p['payment_method']) ?>
                                </span>
                            </td>
                            <td class="p-4 font-mono text-xs text-blue-400"><?= htmlspecialchars($p['transaction_id']) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400"><?= htmlspecialchars((string)($p['gateway_ref'] ?? '-')) ?></td>
                            <td class="p-4 font-bold text-white"><?= formatCurrency((float)$p['amount'], $p['currency']) ?></td>
                            <td class="p-4">
                                <?php if ($p['status'] === 'completed'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Completed</span>
                                <?php elseif ($p['status'] === 'pending'): ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300">Pending</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-400"><?= ucfirst($p['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-xs text-slate-400"><?= date('M d, H:i', strtotime($p['created_at'])) ?></td>
                            <td class="p-4 text-right">
                                <?php if ($p['status'] === 'pending'): ?>
                                <div class="flex items-center justify-end space-x-2">
                                    <form method="POST" action="/admin/payments.php" onsubmit="return confirm('Approve payment and credit user wallet?');">
                                        <?= getCsrfInput() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs px-2.5 py-1 rounded-lg transition font-medium">
                                            Approve
                                        </button>
                                    </form>
                                    <form method="POST" action="/admin/payments.php" onsubmit="return confirm('Reject this deposit request?');">
                                        <?= getCsrfInput() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="bg-rose-600/30 hover:bg-rose-600 text-rose-300 hover:text-white text-xs px-2.5 py-1 rounded-lg transition font-medium">
                                            Reject
                                        </button>
                                    </form>
                                </div>
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
