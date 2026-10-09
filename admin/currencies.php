<?php
/**
 * Mona SMM Panel v2 - Admin Multi-Currency Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Currencies | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_currency') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = cleanInput($_POST['name'] ?? '');
        $symbol = cleanInput($_POST['symbol'] ?? '');
        $rate = (float)($_POST['rate'] ?? 1.0);
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;

        if (empty($code) || empty($symbol) || $rate <= 0) {
            $err = "Currency Code, Symbol, and positive Exchange Rate are required.";
        } else {
            if ($isDefault) {
                $pdo->query("UPDATE currencies SET is_default = 0");
            }
            $pdo->prepare("
                INSERT INTO currencies (code, name, symbol, rate, is_default, status)
                VALUES (:c, :n, :s, :r, :d, 1)
                ON DUPLICATE KEY UPDATE name = :n2, symbol = :s2, rate = :r2, is_default = :d2
            ")->execute([
                ':c' => $code,
                ':n' => $name,
                ':s' => $symbol,
                ':r' => $rate,
                ':d' => $isDefault,
                ':n2' => $name,
                ':s2' => $symbol,
                ':r2' => $rate,
                ':d2' => $isDefault
            ]);
            $msg = "Currency {$code} saved successfully.";
        }
    }
}

$currencies = $pdo->query("SELECT * FROM currencies ORDER BY is_default DESC, code ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Multi-Currency Management</h1>
            <p class="text-slate-400 text-sm mt-1">Configure supported fiat and crypto exchange rates relative to base USD.</p>
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

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Add/Edit Currency Form -->
        <div class="lg:col-span-4 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-lg font-bold text-white">Add / Update Currency</h2>
            <form method="POST" action="/admin/currencies.php" class="space-y-4">
                <?= getCsrfInput() ?>
                <input type="hidden" name="action" value="save_currency">

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Currency Code</label>
                    <input type="text" name="code" required placeholder="e.g. INR, EUR, BRL" maxlength="10"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none uppercase">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Currency Name</label>
                    <input type="text" name="name" required placeholder="e.g. Indian Rupee"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Symbol</label>
                    <input type="text" name="symbol" required placeholder="e.g. ₹, €, $" maxlength="10"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Exchange Rate (per 1 USD)</label>
                    <input type="number" step="0.000001" name="rate" required placeholder="1.000000"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none">
                </div>

                <div class="flex items-center space-x-2 pt-2">
                    <input type="checkbox" name="is_default" id="is_def" value="1" class="rounded">
                    <label for="is_def" class="text-xs text-slate-300">Set as Primary Default Currency</label>
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition text-sm">
                    Save Currency
                </button>
            </form>
        </div>

        <!-- Currencies Table -->
        <div class="lg:col-span-8 bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">Code</th>
                            <th class="p-4">Name</th>
                            <th class="p-4">Symbol</th>
                            <th class="p-4">Rate (1 USD =)</th>
                            <th class="p-4">Default</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($currencies as $c): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-bold text-white"><?= htmlspecialchars($c['code']) ?></td>
                            <td class="p-4 text-slate-300"><?= htmlspecialchars($c['name']) ?></td>
                            <td class="p-4 font-mono text-emerald-400 font-bold"><?= htmlspecialchars($c['symbol']) ?></td>
                            <td class="p-4 font-mono text-slate-200"><?= number_format((float)$c['rate'], 4) ?></td>
                            <td class="p-4">
                                <?php if (!empty($c['is_default'])): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Default</span>
                                <?php else: ?>
                                    <span class="text-slate-600 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
