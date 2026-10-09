<?php
/**
 * Mona SMM Panel v2 - Admin User Management
 * Protected balance adjustments, status toggles, and CSRF protection
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Users Management | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'adjust_balance' && $userId > 0) {
        $type = $_POST['adjust_type'] ?? 'add';
        $amount = (float)($_POST['amount'] ?? 0);
        $reason = cleanInput($_POST['reason'] ?? 'Admin Balance Adjustment');

        if ($amount <= 0) {
            $err = "Amount must be greater than zero.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT id, balance FROM users WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch();

                if (!$user) {
                    $pdo->rollBack();
                    $err = "User not found.";
                } else {
                    if ($type === 'add') {
                        $pdo->prepare("UPDATE users SET balance = balance + :amt WHERE id = :id")
                            ->execute([':amt' => $amount, ':id' => $userId]);
                        logTransaction($userId, 'credit', $amount, $reason, 'admin_adj_' . time());
                        $msg = "Added \${$amount} to user balance successfully.";
                    } else {
                        $pdo->prepare("UPDATE users SET balance = GREATEST(0, balance - :amt) WHERE id = :id")
                            ->execute([':amt' => $amount, ':id' => $userId]);
                        logTransaction($userId, 'debit', $amount, $reason, 'admin_adj_' . time());
                        $msg = "Deducted \${$amount} from user balance successfully.";
                    }
                    $pdo->commit();
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $err = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'change_status' && $userId > 0) {
        $newStatus = cleanInput($_POST['status'] ?? 'active');
        if (in_array($newStatus, ['active', 'suspended', 'banned'])) {
            $pdo->prepare("UPDATE users SET status = :st WHERE id = :id")->execute([':st' => $newStatus, ':id' => $userId]);
            $msg = "User status updated to {$newStatus}.";
        }
    }
}

// User List & Search
$search = cleanInput($_GET['q'] ?? '');
$where = "1=1";
$params = [];
if ($search !== '') {
    $where = "(username LIKE :s OR email LIKE :s2 OR id = :sId)";
    $params[':s'] = "%{$search}%";
    $params[':s2'] = "%{$search}%";
    $params[':sId'] = (int)$search;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE {$where} ORDER BY id DESC LIMIT 50");
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">User Administration</h1>
            <p class="text-slate-400 text-sm mt-1">Manage client profiles, wallet balances, and account statuses.</p>
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

    <!-- Search Form -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
        <form method="GET" action="/admin/users.php" class="max-w-md">
            <input type="text" name="q" placeholder="Search by username, email, ID..." value="<?= htmlspecialchars($search) ?>"
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Username</th>
                        <th class="p-4">Email</th>
                        <th class="p-4">Balance</th>
                        <th class="p-4">Spent</th>
                        <th class="p-4">Role</th>
                        <th class="p-4">Status</th>
                        <th class="p-4 text-right">Balance Adjustment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php foreach ($users as $u): ?>
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="p-4 font-mono text-xs text-slate-400">#<?= $u['id'] ?></td>
                        <td class="p-4 font-bold text-white"><?= htmlspecialchars($u['username']) ?></td>
                        <td class="p-4 text-slate-400 text-xs"><?= htmlspecialchars($u['email']) ?></td>
                        <td class="p-4 font-bold text-emerald-400"><?= formatCurrency((float)$u['balance'], $u['currency']) ?></td>
                        <td class="p-4 text-slate-300 font-mono text-xs"><?= formatCurrency((float)$u['spent'], $u['currency']) ?></td>
                        <td class="p-4">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold uppercase <?= $u['role'] === 'admin' ? 'bg-purple-500/20 text-purple-300' : 'bg-slate-800 text-slate-300' ?>">
                                <?= htmlspecialchars($u['role']) ?>
                            </span>
                        </td>
                        <td class="p-4">
                            <form method="POST" action="/admin/users.php" class="inline-block">
                                <?= getCsrfInput() ?>
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <select name="status" onchange="this.form.submit()" 
                                        class="bg-slate-950 border border-slate-800 rounded-lg px-2 py-1 text-xs text-white focus:outline-none">
                                    <option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="suspended" <?= $u['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    <option value="banned" <?= $u['status'] === 'banned' ? 'selected' : '' ?>>Banned</option>
                                </select>
                            </form>
                        </td>
                        <td class="p-4 text-right">
                            <!-- Quick Balance Adjustment Form -->
                            <form method="POST" action="/admin/users.php" class="inline-flex items-center space-x-1">
                                <?= getCsrfInput() ?>
                                <input type="hidden" name="action" value="adjust_balance">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <select name="adjust_type" class="bg-slate-950 border border-slate-800 rounded px-1.5 py-1 text-xs text-white">
                                    <option value="add">+</option>
                                    <option value="deduct">-</option>
                                </select>
                                <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amt" required
                                       class="w-16 bg-slate-950 border border-slate-800 rounded px-1.5 py-1 text-xs text-white">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white text-xs px-2.5 py-1 rounded transition">
                                    Apply
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
