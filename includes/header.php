<?php
/**
 * Mona SMM Panel v2 - Shared Header
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

$user = currentUser();
$pageTitle = $pageTitle ?? SITE_NAME;
$currentRole = $user['role'] ?? 'guest';
$currency = $user['currency'] ?? 'USD';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="csrf-token" content="<?= getCsrfToken() ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#1e293b">

    <!-- Tailwind CSS (CDN for standalone PHP hosting) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            900: '#1e3a8a'
                        }
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php if (isAdmin()): ?>
    <!-- Chart.js strictly on admin panel -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <?php endif; ?>

    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col font-sans antialiased selection:bg-blue-600 selection:text-white">

    <!-- Top Navigation Bar -->
    <nav class="bg-slate-900/90 backdrop-blur border-b border-slate-800 sticky top-0 z-40" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand Logo -->
                <div class="flex items-center space-x-3">
                    <a href="<?= $user ? ($user['role'] === 'admin' ? '/admin/dashboard.php' : '/user/dashboard.php') : '/index.php' ?>" class="flex items-center space-x-2 font-bold text-xl tracking-tight text-white hover:text-blue-400 transition">
                        <span class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center font-extrabold text-white shadow-lg shadow-blue-500/25">M</span>
                        <span><?= htmlspecialchars(SITE_NAME) ?></span>
                    </a>
                </div>

                <!-- Desktop Menu Links -->
                <div class="hidden md:flex items-center space-x-1">
                    <?php if ($user): ?>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a href="/admin/dashboard.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Dashboard</a>
                            <a href="/admin/orders.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Orders</a>
                            <a href="/admin/services.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Services</a>
                            <a href="/admin/gateways.php" class="px-3 py-2 rounded-lg text-sm font-medium bg-blue-600/20 text-blue-400 border border-blue-500/30 transition">Gateways</a>
                            <a href="/admin/payments.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Payments</a>
                            <a href="/admin/users.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Users</a>
                            <a href="/admin/settings.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Settings</a>
                        <?php else: ?>
                            <a href="/user/dashboard.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Dashboard</a>
                            <a href="/user/new-order.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">New Order</a>
                            <a href="/user/mass-order.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Mass Order</a>
                            <a href="/user/orders.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Orders</a>
                            <a href="/user/services.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Services</a>
                            <a href="/user/add-funds.php" class="px-3 py-2 rounded-lg text-sm font-medium bg-blue-600 text-white shadow-md shadow-blue-500/20 hover:bg-blue-500 transition">Add Funds</a>
                            <a href="/user/tickets.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Support</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="/index.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Home</a>
                        <a href="/services.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Services</a>
                        <a href="/login.php" class="px-3 py-2 rounded-lg text-sm font-medium hover:bg-slate-800 text-slate-300 hover:text-white transition">Sign In</a>
                        <a href="/register.php" class="px-4 py-2 rounded-lg text-sm font-medium bg-blue-600 hover:bg-blue-500 text-white transition shadow-sm">Get Started</a>
                    <?php endif; ?>
                </div>

                <!-- Right Side Widgets (Balance & Profile) -->
                <?php if ($user): ?>
                <div class="hidden md:flex items-center space-x-3">
                    <div class="bg-slate-800/80 border border-slate-700/80 px-3 py-1.5 rounded-lg flex items-center space-x-2">
                        <span class="text-xs text-slate-400 font-medium">Balance:</span>
                        <span class="text-sm font-bold text-emerald-400"><?= formatCurrency((float)$user['balance'], $currency) ?></span>
                    </div>

                    <div class="relative" x-data="{ userMenuOpen: false }">
                        <button @click="userMenuOpen = !userMenuOpen" class="flex items-center space-x-2 p-1.5 rounded-lg hover:bg-slate-800 text-slate-300 hover:text-white transition">
                            <div class="w-8 h-8 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center justify-center font-bold text-xs uppercase">
                                <?= substr($user['username'], 0, 2) ?>
                            </div>
                            <span class="text-sm font-medium"><?= htmlspecialchars($user['username']) ?></span>
                        </button>
                        <div x-show="userMenuOpen" @click.away="userMenuOpen = false" x-cloak class="absolute right-0 mt-2 w-48 bg-slate-900 border border-slate-800 rounded-xl shadow-xl py-1 z-50 text-sm">
                            <a href="/user/profile.php" class="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white">Account Settings</a>
                            <a href="/user/transactions.php" class="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white">Transaction History</a>
                            <a href="/user/referrals.php" class="block px-4 py-2 text-slate-300 hover:bg-slate-800 hover:text-white">Affiliate Referrals</a>
                            <div class="border-t border-slate-800 my-1"></div>
                            <a href="/logout.php" class="block px-4 py-2 text-rose-400 hover:bg-slate-800 hover:text-rose-300">Sign Out</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Mobile Hamburger Button -->
                <div class="flex md:hidden items-center">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Drawer -->
        <div x-show="mobileMenuOpen" x-cloak class="md:hidden border-b border-slate-800 bg-slate-900 px-4 pt-2 pb-4 space-y-1">
            <?php if ($user): ?>
                <div class="py-2 mb-2 border-b border-slate-800 flex items-center justify-between">
                    <span class="text-sm text-slate-400">Balance</span>
                    <span class="text-sm font-bold text-emerald-400"><?= formatCurrency((float)$user['balance'], $currency) ?></span>
                </div>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="/admin/dashboard.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">Admin Dashboard</a>
                    <a href="/admin/gateways.php" class="block px-3 py-2 rounded-lg text-base text-blue-400 bg-slate-800">Payment Gateways</a>
                    <a href="/admin/payments.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">Payments Audit</a>
                    <a href="/admin/orders.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">Orders</a>
                <?php else: ?>
                    <a href="/user/dashboard.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">Dashboard</a>
                    <a href="/user/new-order.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">New Order</a>
                    <a href="/user/add-funds.php" class="block px-3 py-2 rounded-lg text-base text-blue-400 font-semibold hover:bg-slate-800">Add Funds</a>
                    <a href="/user/orders.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">My Orders</a>
                    <a href="/user/tickets.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">Support Tickets</a>
                <?php endif; ?>
                <a href="/logout.php" class="block px-3 py-2 rounded-lg text-base text-rose-400 hover:bg-slate-800">Sign Out</a>
            <?php else: ?>
                <a href="/login.php" class="block px-3 py-2 rounded-lg text-base text-slate-300 hover:bg-slate-800">Sign In</a>
                <a href="/register.php" class="block px-3 py-2 rounded-lg text-base text-blue-400 font-semibold hover:bg-slate-800">Register</a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Main Content Area Wrapper -->
    <main class="flex-1 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
