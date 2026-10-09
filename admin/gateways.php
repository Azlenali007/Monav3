<?php
/**
 * Mona SMM Panel v2 - Admin Payment Gateway Management
 * Configure, enable/disable, and encrypt credentials for all 12 payment gateways
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
$pageTitle = "Payment Gateways | Admin Control Panel";

$allGateways = GatewayManager::getAllGateways($pdo);
$activeGatewayCode = $_GET['code'] ?? array_key_first($allGateways);
$activeGateway = $allGateways[$activeGatewayCode] ?? reset($allGateways);

$successMsg = null;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $code = trim((string)($_POST['gateway_code'] ?? ''));
    if (!isset($allGateways[$code])) {
        $errorMsg = "Invalid gateway selected.";
    } else {
        $saved = GatewayManager::saveGatewayConfig($code, $_POST, $pdo);
        if ($saved) {
            $successMsg = "Gateway configuration for " . htmlspecialchars($allGateways[$code]->getName()) . " updated successfully.";
            // Refresh gateways list
            $allGateways = GatewayManager::getAllGateways($pdo);
            $activeGateway = $allGateways[$code];
            $activeGatewayCode = $code;
        } else {
            $errorMsg = "Failed to update gateway configuration.";
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Payment Gateway Management</h1>
            <p class="text-slate-400 text-sm mt-1">Configure credentials, webhook endpoints, fee margins, and currency settings.</p>
        </div>
        <div class="flex items-center space-x-3">
            <a href="/admin/payments.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-semibold px-4 py-2.5 rounded-xl transition border border-slate-700">
                View Audit Ledger
            </a>
        </div>
    </div>

    <?php if ($successMsg): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 p-4 rounded-xl text-sm flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <span><?= htmlspecialchars($successMsg) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span><?= htmlspecialchars($errorMsg) ?></span>
    </div>
    <?php endif; ?>

    <!-- Gateways Layout: Sidebar + Form -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Sidebar Navigation -->
        <div class="lg:col-span-4 space-y-2">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 space-y-1">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider px-3 py-1 block">Supported Gateways</span>
                <?php foreach ($allGateways as $code => $gw): 
                    $cfg = $gw->getConfig();
                    $isEnabled = !empty($cfg['status']);
                    $isConfigured = $gw->isConfigured();
                    $isActive = ($code === $activeGatewayCode);
                ?>
                <a href="/admin/gateways.php?code=<?= $code ?>" 
                   class="flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition <?= $isActive ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
                    <div class="flex items-center space-x-3">
                        <span class="w-2 h-2 rounded-full <?= $isEnabled ? ($isConfigured ? 'bg-emerald-400' : 'bg-amber-400') : 'bg-slate-600' ?>"></span>
                        <span><?= htmlspecialchars($gw->getName()) ?></span>
                    </div>
                    <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-800 text-blue-200' : 'bg-slate-800 text-slate-400' ?>">
                        <?= $isEnabled ? 'Active' : 'Disabled' ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Webhook Notice -->
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 text-xs text-slate-400 space-y-2">
                <span class="font-bold text-slate-200 block text-sm">Security & Secret Storage</span>
                <p>Sensitive credentials (API secrets, private keys, salt phrases) are stored with <strong>authenticated OpenSSL AES-256-GCM encryption</strong>. Plaintext secrets are never stored in database dumps.</p>
            </div>
        </div>

        <!-- Configuration Form Area -->
        <div class="lg:col-span-8">
            <?php 
                $activeCfg = $activeGateway->getConfig();
                $fields = $activeGateway->getConfigFields();
            ?>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <!-- Gateway Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-slate-800 gap-3">
                    <div>
                        <div class="flex items-center space-x-3">
                            <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($activeGateway->getName()) ?></h2>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase <?= $activeGateway->getType() === 'crypto' ? 'bg-purple-500/20 text-purple-300' : 'bg-blue-500/20 text-blue-300' ?>">
                                <?= htmlspecialchars($activeGateway->getType()) ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">Provider Adapter Code: <code class="text-blue-400 font-mono"><?= htmlspecialchars($activeGatewayCode) ?></code></p>
                    </div>

                    <!-- Configured Status Badge -->
                    <div>
                        <?php if ($activeGateway->isConfigured()): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                Ready for Production
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                Missing Required Keys
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Webhook URL Display -->
                <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 space-y-1">
                    <span class="text-xs font-semibold text-slate-400">Webhook / Instant Notification Service (INS) URL:</span>
                    <div class="flex items-center justify-between bg-slate-900 border border-slate-800 rounded-lg p-2 font-mono text-xs text-blue-400 overflow-x-auto">
                        <span><?= SITE_URL ?>/api/payments.php?gateway=<?= $activeGatewayCode ?></span>
                    </div>
                    <p class="text-[11px] text-slate-500">Copy and register this URL in your <?= htmlspecialchars($activeGateway->getName()) ?> developer/merchant console.</p>
                </div>

                <!-- Edit Form -->
                <form method="POST" action="/admin/gateways.php?code=<?= $activeGatewayCode ?>" class="space-y-6">
                    <?= getCsrfInput() ?>
                    <input type="hidden" name="gateway_code" value="<?= $activeGatewayCode ?>">

                    <!-- Status & Environment Toggles -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Gateway Status</label>
                            <select name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                                <option value="1" <?= !empty($activeCfg['status']) ? 'selected' : '' ?>>Enabled (Active for clients)</option>
                                <option value="0" <?= empty($activeCfg['status']) ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Operating Environment</label>
                            <select name="environment" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                                <option value="live" <?= ($activeCfg['environment'] ?? '') === 'live' ? 'selected' : '' ?>>Production / Live Mode</option>
                                <option value="sandbox" <?= ($activeCfg['environment'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox / Test Mode</option>
                            </select>
                        </div>
                    </div>

                    <!-- Limits & Margins -->
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Fee Margin (%)</label>
                            <input type="number" step="0.01" name="fee_percentage" value="<?= htmlspecialchars((string)($activeCfg['fee_percentage'] ?? '0.00')) ?>"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Min Deposit</label>
                            <input type="number" step="0.01" name="min_amount" value="<?= htmlspecialchars((string)($activeCfg['min_amount'] ?? '1.00')) ?>"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Max Deposit</label>
                            <input type="number" step="0.01" name="max_amount" value="<?= htmlspecialchars((string)($activeCfg['max_amount'] ?? '10000.00')) ?>"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Currency</label>
                            <input type="text" name="currency" value="<?= htmlspecialchars((string)($activeCfg['currency'] ?? 'USD')) ?>"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                        </div>
                    </div>

                    <!-- Dynamic Credential Fields -->
                    <div class="space-y-4 pt-4 border-t border-slate-800">
                        <h3 class="text-sm font-bold text-slate-200">API Credentials</h3>
                        <?php foreach ($fields as $fieldKey => $f): 
                            $isSecret = ($f['type'] ?? '') === 'password';
                            $hasValue = !empty($activeGateway->getCredential($fieldKey));
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-xs font-semibold text-slate-300">
                                    <?= htmlspecialchars($f['label']) ?>
                                    <?php if (!empty($f['required'])): ?><span class="text-rose-400">*</span><?php endif; ?>
                                </label>
                                <?php if ($isSecret && $hasValue): ?>
                                    <span class="text-[11px] text-emerald-400 font-mono">Encrypted & Stored (••••••••)</span>
                                <?php endif; ?>
                            </div>

                            <?php if (($f['type'] ?? '') === 'textarea'): ?>
                                <textarea name="<?= $fieldKey ?>" rows="4" placeholder="<?= htmlspecialchars($f['default'] ?? '') ?>"
                                          class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-white text-sm font-mono focus:outline-none focus:border-blue-500"><?= htmlspecialchars($activeGateway->getCredential($fieldKey) ?: ($f['default'] ?? '')) ?></textarea>
                            <?php else: ?>
                                <input type="<?= $isSecret ? 'password' : 'text' ?>" 
                                       name="<?= $fieldKey ?>" 
                                       placeholder="<?= $isSecret && $hasValue ? 'Leave blank to preserve existing key' : '' ?>"
                                       value="<?= $isSecret ? '' : htmlspecialchars($activeGateway->getCredential($fieldKey)) ?>"
                                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm font-mono focus:outline-none focus:border-blue-500">
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Save Button -->
                    <div class="pt-4 border-t border-slate-800 flex justify-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold px-6 py-2.5 rounded-xl transition shadow-lg shadow-blue-500/20">
                            Save Gateway Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
