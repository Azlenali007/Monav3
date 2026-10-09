<?php
/**
 * Mona SMM Panel v2 - User Financial Transactions Ledger
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Transactions Ledger | " . SITE_NAME;

$stmt = $pdo->prepare("
    SELECT * FROM transactions 
    WHERE user_id = :uid 
    ORDER BY id DESC LIMIT 100
");
$stmt->execute([':uid' => $user['id']]);
$transactions = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Transaction History</h1>
            <p class="text-slate-400 text-sm mt-1">Immutable ledger of debits, deposits, refunds, and affiliate credits.</p>
        </div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-2 flex items-center space-x-3">
            <span class="text-xs text-slate-400">Balance:</span>
            <span class="text-sm font-bold text-emerald-400"><?= formatCurrency((float)$user['balance'], $user['currency']) ?></span>
        </div>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Type</th>
                        <th class="p-4">Amount</th>
                        <th class="p-4">Description</th>
                        <th class="p-4">Reference</th>
                        <th class="p-4">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="6" class="p-8 text-center text-slate-500">No transaction records found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $t['id'] ?></td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold capitalize <?= $t['type'] === 'credit' ? 'bg-emerald-500/20 text-emerald-400' : ($t['type'] === 'refund' ? 'bg-blue-500/20 text-blue-400' : 'bg-slate-800 text-slate-300') ?>">
                                    <?= htmlspecialchars($t['type']) ?>
                                </span>
                            </td>
                            <td class="p-4 font-bold <?= $t['type'] === 'credit' || $t['type'] === 'refund' ? 'text-emerald-400' : 'text-slate-300' ?>">
                                <?= ($t['type'] === 'credit' || $t['type'] === 'refund') ? '+' : '-' ?><?= formatCurrency((float)$t['amount'], $t['currency']) ?>
                            </td>
                            <td class="p-4 text-slate-200"><?= htmlspecialchars($t['description']) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400"><?= htmlspecialchars((string)($t['reference_id'] ?? '-')) ?></td>
                            <td class="p-4 text-xs text-slate-400"><?= date('M d, Y H:i', strtotime($t['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
