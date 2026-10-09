<?php
/**
 * Mona SMM Panel v2 - Payment Status Inspection Page
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways/GatewayManager.php';

use Mona\Gateways\GatewayManager;

$user = requireLogin();
$pdo = getDB();
$txId = trim((string)($_GET['tx'] ?? ''));

if (empty($txId)) {
    header("Location: /user/add-funds.php");
    exit;
}

// Fetch payment record belonging exclusively to this user
$stmt = $pdo->prepare("SELECT * FROM payments WHERE transaction_id = :tx AND user_id = :uid LIMIT 1");
$stmt->execute([':tx' => $txId, ':uid' => $user['id']]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment reference not found.");
}

// If payment is pending and gateway has a status check implementation, query it
if ($payment['status'] === 'pending') {
    $gateway = GatewayManager::getGateway($payment['payment_method'], $pdo);
    if ($gateway && !empty($payment['gateway_ref'])) {
        $statusCheck = $gateway->checkPaymentStatus($payment['gateway_ref'], $payment['transaction_id']);
        if (($statusCheck['status'] ?? '') === 'completed') {
            GatewayManager::processPaymentCredit(
                $pdo,
                (int)$payment['id'],
                $payment['gateway_ref'],
                (float)($statusCheck['amount'] ?? $payment['net_amount']),
                (string)($statusCheck['currency'] ?? $payment['currency']),
                ['source' => 'status_poll']
            );
            // Refresh record
            $stmt->execute([':tx' => $txId, ':uid' => $user['id']]);
            $payment = $stmt->fetch();
        }
    }
}

$pageTitle = "Payment Status | " . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-md mx-auto my-12 bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center shadow-2xl">
    <?php if ($payment['status'] === 'completed'): ?>
        <div class="w-16 h-16 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-1">Deposit Successful!</h1>
        <p class="text-slate-400 text-sm mb-6">Your funds have been securely credited to your account wallet.</p>
    <?php elseif ($payment['status'] === 'pending'): ?>
        <div class="w-16 h-16 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center mx-auto mb-4 animate-pulse">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-1">Awaiting Confirmation</h1>
        <p class="text-slate-400 text-sm mb-6">We are waiting for payment gateway confirmation. This page can be safely refreshed.</p>
    <?php else: ?>
        <div class="w-16 h-16 rounded-full bg-rose-500/20 text-rose-400 flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-1">Payment <?= ucfirst($payment['status']) ?></h1>
        <p class="text-slate-400 text-sm mb-6">The payment could not be completed or was canceled.</p>
    <?php endif; ?>

    <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 text-left text-sm space-y-2 mb-6">
        <div class="flex justify-between text-slate-400">
            <span>Transaction ID:</span>
            <span class="font-mono text-slate-200"><?= htmlspecialchars($payment['transaction_id']) ?></span>
        </div>
        <div class="flex justify-between text-slate-400">
            <span>Payment Method:</span>
            <span class="capitalize text-slate-200"><?= htmlspecialchars($payment['payment_method']) ?></span>
        </div>
        <div class="flex justify-between text-slate-400">
            <span>Amount:</span>
            <span class="font-bold text-white"><?= formatCurrency((float)$payment['amount'], $payment['currency']) ?></span>
        </div>
        <div class="flex justify-between text-slate-400">
            <span>Status:</span>
            <span class="font-semibold uppercase text-xs <?= $payment['status'] === 'completed' ? 'text-emerald-400' : ($payment['status'] === 'pending' ? 'text-amber-400' : 'text-rose-400') ?>">
                <?= htmlspecialchars($payment['status']) ?>
            </span>
        </div>
    </div>

    <div class="flex items-center space-x-3">
        <?php if ($payment['status'] === 'pending'): ?>
            <button onclick="window.location.reload()" class="flex-1 bg-slate-800 hover:bg-slate-700 text-white font-medium py-2.5 rounded-xl transition">
                Refresh Status
            </button>
        <?php endif; ?>
        <a href="/user/dashboard.php" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-medium py-2.5 rounded-xl transition text-center">
            Go to Dashboard
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
