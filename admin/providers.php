<?php
/**
 * Mona SMM Panel v2 - Admin Provider Management
 * Setup upstream SMM APIs and check live account balances
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Providers Management | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_provider') {
        $name = cleanInput($_POST['name'] ?? '');
        $url = trim((string)($_POST['api_url'] ?? ''));
        $key = trim((string)($_POST['api_key'] ?? ''));

        if (empty($name) || empty($url) || empty($key)) {
            $err = "All provider fields are required.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO providers (name, api_url, api_key, status, created_at) VALUES (:n, :u, :k, 1, NOW())");
            $stmt->execute([':n' => $name, ':u' => $url, ':k' => $key]);
            $msg = "Provider added successfully.";
        }
    } elseif ($action === 'sync_balance') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $provStmt = $pdo->prepare("SELECT * FROM providers WHERE id = :id");
        $provStmt->execute([':id' => $providerId]);
        $provider = $provStmt->fetch();

        if ($provider) {
            $res = callProviderApi($provider, ['action' => 'balance']);
            if (isset($res['balance'])) {
                $bal = (float)$res['balance'];
                $curr = strtoupper($res['currency'] ?? 'USD');
                $pdo->prepare("UPDATE providers SET balance = :b, currency = :c WHERE id = :id")
                    ->execute([':b' => $bal, ':c' => $curr, ':id' => $providerId]);
                $msg = "Provider balance updated: {$bal} {$curr}";
            } else {
                $err = "Failed to query balance: " . ($res['error'] ?? 'Unknown error');
            }
        }
    }
}

$providers = $pdo->query("SELECT * FROM providers ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">API Providers</h1>
            <p class="text-slate-400 text-sm mt-1">Connect third-party SMM providers to automate order routing and rate sync.</p>
        </div>
        <a href="/admin/import-services.php" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-4 py-2 rounded-xl transition">
            Import Services &rarr;
        </a>
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
        <!-- Add Provider Form -->
        <div class="lg:col-span-4 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-lg font-bold text-white">Add New Provider</h2>
            <form method="POST" action="/admin/providers.php" class="space-y-4">
                <?= getCsrfInput() ?>
                <input type="hidden" name="action" value="add_provider">

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Provider Name</label>
                    <input type="text" name="name" required placeholder="e.g. PeakSMM"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">API URL Endpoint</label>
                    <input type="url" name="api_url" required placeholder="https://provider.com/api/v2"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">API Key</label>
                    <input type="password" name="api_key" required placeholder="API Key"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition text-sm">
                    Connect Provider
                </button>
            </form>
        </div>

        <!-- Providers List Table -->
        <div class="lg:col-span-8 bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">ID</th>
                            <th class="p-4">Name</th>
                            <th class="p-4">API URL</th>
                            <th class="p-4">Balance</th>
                            <th class="p-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($providers as $p): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $p['id'] ?></td>
                            <td class="p-4 font-bold text-white"><?= htmlspecialchars($p['name']) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-400 truncate max-w-xs"><?= htmlspecialchars($p['api_url']) ?></td>
                            <td class="p-4 font-bold text-emerald-400 font-mono"><?= formatCurrency((float)$p['balance'], $p['currency']) ?></td>
                            <td class="p-4 text-right">
                                <form method="POST" action="/admin/providers.php" class="inline-block">
                                    <?= getCsrfInput() ?>
                                    <input type="hidden" name="action" value="sync_balance">
                                    <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs px-3 py-1.5 rounded-lg transition border border-slate-700">
                                        Check Balance
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
