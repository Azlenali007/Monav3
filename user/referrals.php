<?php
/**
 * Mona SMM Panel v2 - Affiliate Referrals Dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Affiliate Referrals | " . SITE_NAME;

// Fetch referral summary
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_referred, COALESCE(SUM(total_earned), 0) as total_earnings 
    FROM referrals 
    WHERE referrer_id = :uid
");
$stmt->execute([':uid' => $user['id']]);
$stats = $stmt->fetch() ?: ['total_referred' => 0, 'total_earnings' => 0];

// Referral link
$refLink = SITE_URL . "/register.php?ref=" . urlencode($user['referral_code'] ?? '');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Affiliate Program</h1>
        <p class="text-slate-400 text-sm mt-1">Earn automatic commission on all completed wallet deposits from your referred clients.</p>
    </div>

    <!-- Referral Link Box -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-4">
        <h2 class="text-lg font-bold text-white">Your Unique Referral Link</h2>
        <div class="flex items-center space-x-3 bg-slate-950 border border-slate-800 rounded-xl p-3">
            <input type="text" readonly value="<?= htmlspecialchars($refLink) ?>"
                   class="bg-transparent text-blue-400 font-mono text-sm flex-1 outline-none select-all">
            <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($refLink) ?>'); Swal.fire('Copied!', 'Referral link copied to clipboard.', 'success');"
                    class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold px-4 py-2 rounded-lg transition">
                Copy Link
            </button>
        </div>
        <p class="text-xs text-slate-400">Share this link. When a customer registers and completes a deposit, your account wallet receives an instant 5% commission credit.</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 max-w-xl">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Referred Users</span>
            <div class="text-3xl font-black text-white"><?= number_format((int)$stats['total_referred']) ?></div>
        </div>
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-2">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Commission Earned</span>
            <div class="text-3xl font-black text-emerald-400"><?= formatCurrency((float)$stats['total_earnings'], 'USD') ?></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
