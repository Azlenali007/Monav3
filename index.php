<?php
/**
 * Mona SMM Panel v2 - Public Homepage & Landing
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();

// Quick statistics
$stats = [
    'orders' => 0,
    'services' => 0,
    'users' => 0
];

try {
    $stats['orders'] = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $stats['services'] = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE status = 1")->fetchColumn();
    $stats['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Exception $e) {
    // Database might be uninitialized during installer setup
}

$pageTitle = SITE_NAME . " | #1 Social Media Marketing Reseller Panel";
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-20 py-8">
    <!-- Hero Section -->
    <div class="text-center max-w-3xl mx-auto space-y-6">
        <span class="inline-flex items-center space-x-2 px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
            <span>⚡ Automated Provider APIs & Instant Delivery</span>
        </span>
        <h1 class="text-4xl sm:text-6xl font-black text-white tracking-tight leading-tight">
            Elevate Your Social Reach with <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-indigo-400"><?= htmlspecialchars(SITE_NAME) ?></span>
        </h1>
        <p class="text-slate-400 text-lg sm:text-xl">
            The premier SMM reseller platform with seamless API automation, real payment gateways, instant order dispatch, and competitive wholesale pricing.
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-4">
            <?php if (isLoggedIn()): ?>
                <a href="/user/new-order.php" class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold transition shadow-lg shadow-blue-500/25">
                    Order Services
                </a>
                <a href="/user/add-funds.php" class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold transition border border-slate-700">
                    Deposit Funds
                </a>
            <?php else: ?>
                <a href="/register.php" class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold transition shadow-lg shadow-blue-500/25">
                    Get Started Now
                </a>
                <a href="/services.php" class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold transition border border-slate-700">
                    Explore Services Catalog
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Live Statistics Counter -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-5xl mx-auto">
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 text-center space-y-1">
            <span class="text-3xl font-black text-white"><?= number_format($stats['orders']) ?>+</span>
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Processed Orders</p>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 text-center space-y-1">
            <span class="text-3xl font-black text-blue-400"><?= number_format($stats['services']) ?>+</span>
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Active Services</p>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 text-center space-y-1">
            <span class="text-3xl font-black text-emerald-400"><?= number_format($stats['users']) ?>+</span>
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Satisfied Clients</p>
        </div>
    </div>

    <!-- Feature Pillars -->
    <div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-8">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 space-y-4">
            <div class="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center font-bold text-xl">
                💳
            </div>
            <h3 class="text-xl font-bold text-white">12+ Real Payment Gateways</h3>
            <p class="text-slate-400 text-sm leading-relaxed">
                Direct integration with Razorpay, Cashfree, PhonePe, PayU, PayPal, Stripe, Verifone, Cryptomus, NOWPayments, CoinPayments, and Binance Pay with atomic wallet crediting.
            </p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 space-y-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center font-bold text-xl">
                ⚡
            </div>
            <h3 class="text-xl font-bold text-white">Full Automation & SMM v2</h3>
            <p class="text-slate-400 text-sm leading-relaxed">
                Connect external providers with zero friction. Scheduled cron workers poll upstream order completion, handle drip-feed execution, and issue automated refunds.
            </p>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 space-y-4">
            <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center font-bold text-xl">
                🛡️
            </div>
            <h3 class="text-xl font-bold text-white">Enterprise Security</h3>
            <p class="text-slate-400 text-sm leading-relaxed">
                Strict PDO parameters, OpenSSL AES-256-GCM credential encryption, CSRF mitigation, session fixation protection, and concurrency-safe transactions.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
