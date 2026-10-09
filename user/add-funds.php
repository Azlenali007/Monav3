<?php
/**
 * Mona SMM Panel v2 - User Add Funds Portal
 * Real payment gateway integration with official checkout flows
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways/GatewayManager.php';

use Mona\Gateways\GatewayManager;

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Add Funds | " . SITE_NAME;

$enabledGateways = GatewayManager::getEnabledGateways($pdo);
$error = null;
$customPaymentData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $gatewayCode = strtolower(trim((string)($_POST['gateway'] ?? '')));
    $amount = (float)($_POST['amount'] ?? 0);

    if (empty($enabledGateways[$gatewayCode])) {
        $error = "Selected payment method is currently unavailable or disabled.";
    } else {
        $gateway = $enabledGateways[$gatewayCode];
        $config = $gateway->getConfig();
        $min = (float)($config['min_amount'] ?? 1);
        $max = (float)($config['max_amount'] ?? 10000);
        $feePct = (float)($config['fee_percentage'] ?? 0);
        $currency = strtoupper((string)($config['currency'] ?? 'USD'));

        if ($amount < $min || $amount > $max) {
            $error = sprintf("Deposit amount must be between %s and %s.", formatCurrency($min, $currency), formatCurrency($max, $currency));
        } else {
            $fee = round(($amount * $feePct) / 100, 4);
            $netAmount = $amount;
            $transactionId = 'tx_' . bin2hex(random_bytes(6)) . '_' . time();

            // Insert initial pending payment record
            $stmt = $pdo->prepare("
                INSERT INTO payments 
                (user_id, payment_method, amount, fee, net_amount, currency, transaction_id, status, created_at)
                VALUES (:uid, :method, :amt, :fee, :net, :curr, :tx, 'pending', NOW())
            ");
            $stmt->execute([
                ':uid' => $user['id'],
                ':method' => $gatewayCode,
                ':amt' => $amount,
                ':fee' => $fee,
                ':net' => $netAmount,
                ':curr' => $currency,
                ':tx' => $transactionId
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $paymentRecord = [
                'id' => $paymentId,
                'user_id' => $user['id'],
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'currency' => $currency,
                'transaction_id' => $transactionId
            ];

            // Delegate checkout creation to gateway adapter
            $result = $gateway->createPayment($paymentRecord, $user);

            if ($result['status'] === 'redirect' && !empty($result['redirect_url'])) {
                if (!empty($result['gateway_ref'])) {
                    $pdo->prepare("UPDATE payments SET gateway_ref = :ref WHERE id = :id")
                        ->execute([':ref' => $result['gateway_ref'], ':id' => $paymentId]);
                }
                header("Location: " . $result['redirect_url']);
                exit;
            } elseif ($result['status'] === 'custom') {
                if (!empty($result['gateway_ref'])) {
                    $pdo->prepare("UPDATE payments SET gateway_ref = :ref WHERE id = :id")
                        ->execute([':ref' => $result['gateway_ref'], ':id' => $paymentId]);
                }
                $customPaymentData = [
                    'gateway' => $gatewayCode,
                    'result' => $result,
                    'payment' => $paymentRecord
                ];
            } else {
                $error = $result['error'] ?? 'Payment initiation failed. Please try again or choose another method.';
                $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = :id")->execute([':id' => $paymentId]);
            }
        }
    }
}

// Fetch user recent deposit history
$historyStmt = $pdo->prepare("
    SELECT id, payment_method, amount, fee, currency, transaction_id, status, created_at 
    FROM payments 
    WHERE user_id = :uid 
    ORDER BY id DESC LIMIT 10
");
$historyStmt->execute([':uid' => $user['id']]);
$recentPayments = $historyStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-8" x-data="{
    selectedGateway: '<?= array_key_first($enabledGateways) ?? '' ?>',
    amount: 10,
    gateways: <?= htmlspecialchars(json_encode(array_map(fn($g) => $g->getConfig(), $enabledGateways)), ENT_QUOTES, 'UTF-8') ?>,
    get currentConfig() {
        return this.gateways[this.selectedGateway] || { fee_percentage: 0, min_amount: 1, max_amount: 10000, currency: 'USD' };
    },
    get calculatedFee() {
        return ((parseFloat(this.amount) || 0) * (parseFloat(this.currentConfig.fee_percentage) || 0) / 100).toFixed(2);
    },
    get totalCharge() {
        return ((parseFloat(this.amount) || 0) + parseFloat(this.calculatedFee)).toFixed(2);
    }
}">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Add Funds to Wallet</h1>
            <p class="text-slate-400 text-sm mt-1">Select a verified payment gateway and securely fund your account.</p>
        </div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-2 flex items-center space-x-3 self-start">
            <span class="text-xs text-slate-400">Current Balance:</span>
            <span class="text-lg font-extrabold text-emerald-400"><?= formatCurrency((float)$user['balance'], $user['currency']) ?></span>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Custom Checkout Container (Modal/Overlay for Razorpay / Cashfree / PayU / Manual) -->
    <?php if ($customPaymentData): ?>
    <div class="bg-blue-600/10 border border-blue-500/30 rounded-2xl p-6 text-center space-y-4">
        <h2 class="text-xl font-bold text-white">Payment Initiated</h2>
        <p class="text-slate-300 text-sm">Transaction Reference: <code class="bg-slate-900 px-2 py-1 rounded text-blue-400"><?= htmlspecialchars($customPaymentData['payment']['transaction_id']) ?></code></p>

        <?php if ($customPaymentData['gateway'] === 'razorpay'): ?>
            <!-- Razorpay Checkout -->
            <button id="rzp-button" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold px-6 py-3 rounded-xl transition shadow-lg shadow-blue-500/25">
                Complete Payment via Razorpay
            </button>
            <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
            <script>
                const options = <?= json_encode($customPaymentData['result']['custom_data']) ?>;
                options.handler = function(response) {
                    window.location.href = '/user/payment-status.php?tx=<?= urlencode($customPaymentData['payment']['transaction_id']) ?>&rzp_payment_id=' + response.razorpay_payment_id;
                };
                const rzp = new Razorpay(options);
                document.getElementById('rzp-button').onclick = function(e){
                    rzp.open();
                    e.preventDefault();
                };
                rzp.open();
            </script>

        <?php elseif ($customPaymentData['gateway'] === 'payu'): ?>
            <!-- PayU Auto-submit Form -->
            <form id="payu-form" method="POST" action="<?= htmlspecialchars($customPaymentData['result']['custom_data']['action_url']) ?>">
                <?php foreach ($customPaymentData['result']['custom_data']['params'] as $k => $v): ?>
                    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                <?php endforeach; ?>
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold px-6 py-3 rounded-xl transition">
                    Proceed to PayU Checkout
                </button>
            </form>
            <script>document.getElementById('payu-form').submit();</script>

        <?php elseif ($customPaymentData['gateway'] === 'manual'): ?>
            <!-- Manual Bank Details -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 text-left max-w-lg mx-auto space-y-3">
                <h3 class="font-semibold text-white">Transfer Instructions:</h3>
                <pre class="bg-slate-950 p-4 rounded-lg text-slate-300 text-xs font-mono whitespace-pre-wrap"><?= htmlspecialchars($customPaymentData['result']['instructions'] ?? '') ?></pre>
                <div class="pt-2 text-center">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-amber-500/20 text-amber-300 border border-amber-500/30">
                        Status: Pending Admin Verification
                    </span>
                    <p class="text-slate-400 text-xs mt-2">Your deposit will be credited once verified by administrators.</p>
                </div>
            </div>

        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Main Payment Selection Card -->
    <?php if (empty($enabledGateways)): ?>
    <div class="bg-slate-900/50 border border-slate-800 rounded-2xl p-12 text-center space-y-3">
        <div class="w-12 h-12 rounded-xl bg-slate-800 text-slate-400 flex items-center justify-center mx-auto">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
        </div>
        <h2 class="text-lg font-bold text-white">No Payment Methods Configured</h2>
        <p class="text-slate-400 text-sm max-w-md mx-auto">There are currently no active automated payment gateways available. Please check back later or contact site support.</p>
    </div>
    <?php else: ?>
    <div class="bg-slate-900/80 backdrop-blur border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="/user/add-funds.php" class="space-y-6">
            <?= getCsrfInput() ?>

            <!-- Gateway Selection Grid -->
            <div>
                <label class="block text-sm font-semibold text-slate-200 mb-3">1. Select Payment Method</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                    <?php foreach ($enabledGateways as $code => $gw): 
                        $cfg = $gw->getConfig();
                    ?>
                    <label class="relative flex items-center p-4 rounded-xl border cursor-pointer transition"
                           :class="selectedGateway === '<?= $code ?>' ? 'border-blue-500 bg-blue-600/10' : 'border-slate-800 bg-slate-900 hover:border-slate-700'">
                        <input type="radio" name="gateway" value="<?= $code ?>" class="sr-only" x-model="selectedGateway">
                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white text-sm"><?= htmlspecialchars($gw->getName()) ?></span>
                                <span class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full"
                                      :class="'<?= $gw->getType() ?>' === 'crypto' ? 'bg-purple-500/20 text-purple-300' : ('<?= $gw->getType() ?>' === 'manual' ? 'bg-amber-500/20 text-amber-300' : 'bg-blue-500/20 text-blue-300')">
                                    <?= htmlspecialchars($gw->getType()) ?>
                                </span>
                            </div>
                            <div class="text-xs text-slate-400 mt-1 flex items-center justify-between">
                                <span>Fee: <?= (float)($cfg['fee_percentage'] ?? 0) ?>%</span>
                                <span><?= htmlspecialchars($cfg['currency'] ?? 'USD') ?></span>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Amount Input & Currency Summary -->
            <div>
                <label class="block text-sm font-semibold text-slate-200 mb-2">2. Enter Deposit Amount</label>
                <div class="relative rounded-xl shadow-sm max-w-md">
                    <input type="number" name="amount" step="0.01" min="1" required x-model="amount"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl py-3 px-4 text-white text-lg font-bold placeholder-slate-600 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400 font-semibold" x-text="currentConfig.currency">
                    </div>
                </div>
                <div class="text-xs text-slate-500 mt-2 flex items-center space-x-4">
                    <span>Min: <strong class="text-slate-400" x-text="(currentConfig.min_amount || 1) + ' ' + currentConfig.currency"></strong></span>
                    <span>Max: <strong class="text-slate-400" x-text="(currentConfig.max_amount || 10000) + ' ' + currentConfig.currency"></strong></span>
                </div>
            </div>

            <!-- Manual Transfer UTR Field -->
            <div x-show="selectedGateway === 'manual'" x-cloak class="border-t border-slate-800 pt-4">
                <label class="block text-sm font-semibold text-slate-200 mb-2">Transaction ID / UTR / Reference Number</label>
                <input type="text" name="manual_reference" placeholder="Enter bank or UPI transaction reference"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl py-2.5 px-4 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <!-- Calculation Summary Box -->
            <div class="bg-slate-950/60 border border-slate-800 rounded-xl p-4 text-sm space-y-2">
                <div class="flex justify-between text-slate-400">
                    <span>Deposit Amount:</span>
                    <span class="text-white font-medium" x-text="parseFloat(amount || 0).toFixed(2) + ' ' + currentConfig.currency"></span>
                </div>
                <div class="flex justify-between text-slate-400">
                    <span>Processing Fee (<span x-text="currentConfig.fee_percentage || 0"></span>%):</span>
                    <span class="text-slate-300" x-text="calculatedFee + ' ' + currentConfig.currency"></span>
                </div>
                <div class="border-t border-slate-800 pt-2 flex justify-between font-bold text-white text-base">
                    <span>Total Charge:</span>
                    <span class="text-blue-400" x-text="totalCharge + ' ' + currentConfig.currency"></span>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3.5 px-6 rounded-xl transition shadow-lg shadow-blue-500/20 flex items-center justify-center space-x-2">
                <span>Proceed to Secure Payment</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                </svg>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Recent Payments History -->
    <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6">
        <h2 class="text-lg font-bold text-white mb-4">Recent Deposit History</h2>
        <?php if (empty($recentPayments)): ?>
            <p class="text-slate-500 text-sm text-center py-4">No payment records found.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="pb-3">Reference</th>
                            <th class="pb-3">Method</th>
                            <th class="pb-3">Amount</th>
                            <th class="pb-3">Status</th>
                            <th class="pb-3">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($recentPayments as $p): ?>
                        <tr>
                            <td class="py-3 font-mono text-xs text-slate-300"><?= htmlspecialchars($p['transaction_id']) ?></td>
                            <td class="py-3 capitalize text-slate-200"><?= htmlspecialchars($p['payment_method']) ?></td>
                            <td class="py-3 font-semibold text-white"><?= formatCurrency((float)$p['amount'], $p['currency']) ?></td>
                            <td class="py-3">
                                <?php if ($p['status'] === 'completed'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400">Completed</span>
                                <?php elseif ($p['status'] === 'pending'): ?>
                                    <a href="/user/payment-status.php?tx=<?= urlencode($p['transaction_id']) ?>" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300 hover:underline">Pending</a>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-500/20 text-rose-400"><?= ucfirst($p['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-slate-400 text-xs"><?= date('M d, Y H:i', strtotime($p['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
