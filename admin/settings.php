<?php
/**
 * Mona SMM Panel v2 - Admin General Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "System Settings | Admin Control Panel";

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $siteName = cleanInput($_POST['site_name'] ?? 'Mona SMM Panel');
    $currency = strtoupper(cleanInput($_POST['currency'] ?? 'USD'));
    $maintenance = !empty($_POST['maintenance_mode']) ? '1' : '0';
    $referralRate = cleanInput($_POST['referral_commission_rate'] ?? '5.00');

    setSetting('site_name', $siteName);
    setSetting('currency', $currency);
    setSetting('maintenance_mode', $maintenance);
    setSetting('referral_commission_rate', $referralRate);

    $msg = "System settings updated successfully.";
}

$siteName = getSetting('site_name', SITE_NAME);
$currency = getSetting('currency', DEFAULT_CURRENCY);
$maintenance = getSetting('maintenance_mode', '0');
$referralRate = getSetting('referral_commission_rate', '5.00');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">System Configuration</h1>
        <p class="text-slate-400 text-sm mt-1">Configure global platform options, currency defaults, and affiliate margins.</p>
    </div>

    <?php if ($msg): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="/admin/settings.php" class="space-y-6">
            <?= getCsrfInput() ?>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Platform Name</label>
                <input type="text" name="site_name" value="<?= htmlspecialchars((string)$siteName) ?>" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Default Base Currency</label>
                    <input type="text" name="currency" value="<?= htmlspecialchars((string)$currency) ?>" required maxlength="10"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none uppercase">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Affiliate Commission (%)</label>
                    <input type="number" step="0.1" name="referral_commission_rate" value="<?= htmlspecialchars((string)$referralRate) ?>" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none">
                </div>
            </div>

            <div class="pt-2">
                <label class="flex items-center space-x-3 cursor-pointer">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= $maintenance === '1' ? 'checked' : '' ?> class="rounded">
                    <div>
                        <span class="text-sm font-semibold text-white block">Maintenance Mode</span>
                        <span class="text-xs text-slate-400">Temporarily restrict public access to administrators only.</span>
                    </div>
                </label>
            </div>

            <div class="pt-4 border-t border-slate-800 flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 px-6 rounded-xl transition text-sm">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
