<?php
/**
 * Mona SMM Panel v2 - Place New Order
 * Strict server-side pricing recalculation and atomic transactional balance debit
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "New Order | " . SITE_NAME;

$error = null;
$success = null;

// Fetch active categories and services
$categories = $pdo->query("SELECT * FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$servicesStmt = $pdo->query("SELECT * FROM services WHERE status = 1 ORDER BY category_id ASC, sort_order ASC, id ASC");
$allServices = $servicesStmt->fetchAll();

$groupedServices = [];
$serviceMap = [];
foreach ($allServices as $s) {
    $groupedServices[$s['category_id']][] = $s;
    $serviceMap[$s['id']] = $s;
}

$preselectedServiceId = (int)($_GET['service'] ?? 0);
$preselectedCategoryId = 0;
if ($preselectedServiceId > 0 && isset($serviceMap[$preselectedServiceId])) {
    $preselectedCategoryId = (int)$serviceMap[$preselectedServiceId]['category_id'];
} elseif (!empty($categories)) {
    $preselectedCategoryId = (int)$categories[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link = trim((string)($_POST['link'] ?? ''));
    $quantity = (int)($_POST['quantity'] ?? 0);

    if (!isset($serviceMap[$serviceId])) {
        $error = "Selected service is invalid or unavailable.";
    } elseif (empty($link)) {
        $error = "Please provide a valid target link.";
    } else {
        $service = $serviceMap[$serviceId];
        $min = (int)$service['min_quantity'];
        $max = (int)$service['max_quantity'];

        if ($quantity < $min || $quantity > $max) {
            $error = "Quantity must be between " . number_format($min) . " and " . number_format($max) . ".";
        } else {
            // STRICT SERVER-SIDE CHARGE RECOMPUTATION
            // Calculate base rate per 1,000 units
            $rate = (float)$service['rate'];
            
            // Check for custom user rates if configured
            if (!empty($user['custom_rates'])) {
                $customRates = json_decode((string)$user['custom_rates'], true);
                if (is_array($customRates) && isset($customRates[$serviceId])) {
                    $rate = (float)$customRates[$serviceId];
                }
            }

            $charge = round(($rate * $quantity) / 1000, 4);

            try {
                $pdo->beginTransaction();

                // Lock user row exclusively to prevent race condition balance overdraft
                $lockStmt = $pdo->prepare("SELECT balance FROM users WHERE id = :id FOR UPDATE");
                $lockStmt->execute([':id' => $user['id']]);
                $currentBalance = (float)$lockStmt->fetchColumn();

                if ($currentBalance < $charge) {
                    $pdo->rollBack();
                    $error = "Insufficient funds. Required: " . formatCurrency($charge, 'USD') . " (Available: " . formatCurrency($currentBalance, $user['currency']) . "). Please add funds.";
                } else {
                    // Deduct balance and update spent atomically
                    $pdo->prepare("UPDATE users SET balance = balance - :chg, spent = spent + :chg WHERE id = :id")
                        ->execute([':chg' => $charge, ':id' => $user['id']]);

                    // Insert Order Record
                    $orderStmt = $pdo->prepare("
                        INSERT INTO orders 
                        (user_id, service_id, provider_id, link, quantity, charge, status, created_at)
                        VALUES (:uid, :sid, :pid, :link, :qty, :chg, 'pending', NOW())
                    ");
                    $orderStmt->execute([
                        ':uid' => $user['id'],
                        ':sid' => $serviceId,
                        ':pid' => $service['provider_id'] ?: null,
                        ':link' => $link,
                        ':qty' => $quantity,
                        ':chg' => $charge
                    ]);
                    $orderId = (int)$pdo->lastInsertId();

                    // Log ledger entry in transactions
                    $pdo->prepare("
                        INSERT INTO transactions (user_id, type, amount, currency, description, reference_id, created_at)
                        VALUES (:uid, 'debit', :amt, 'USD', :desc, :ref, NOW())
                    ")->execute([
                        ':uid' => $user['id'],
                        ':amt' => $charge,
                        ':desc' => "Order #{$orderId}: " . substr($service['name'], 0, 40),
                        ':ref' => "order_{$orderId}"
                    ]);

                    $pdo->commit();

                    // Forward immediately to upstream provider if configured
                    if (!empty($service['provider_id']) && !empty($service['provider_service_id'])) {
                        $provStmt = $pdo->prepare("SELECT * FROM providers WHERE id = :pid LIMIT 1");
                        $provStmt->execute([':pid' => $service['provider_id']]);
                        $provider = $provStmt->fetch();

                        if ($provider) {
                            $apiParams = [
                                'action' => 'add',
                                'service' => $service['provider_service_id'],
                                'link' => $link,
                                'quantity' => $quantity
                            ];
                            $apiRes = callProviderApi($provider, $apiParams);

                            if (!empty($apiRes['order'])) {
                                $pdo->prepare("UPDATE orders SET provider_order_id = :p_oid, status = 'processing' WHERE id = :id")
                                    ->execute([':p_oid' => (string)$apiRes['order'], ':id' => $orderId]);
                            } elseif (!empty($apiRes['error'])) {
                                $pdo->prepare("UPDATE orders SET error = :err WHERE id = :id")
                                    ->execute([':err' => (string)$apiRes['error'], ':id' => $orderId]);
                            }
                        }
                    }

                    $success = "Order #{$orderId} placed successfully! Status: Processing.";
                    // Refresh user balance cache
                    $user = currentUser();
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Order placement error: " . $e->getMessage());
                $error = "An unexpected error occurred while processing your order. Please try again.";
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6" x-data="{
    categoryId: '<?= $preselectedCategoryId ?>',
    serviceId: '<?= $preselectedServiceId ?>',
    quantity: 100,
    servicesGrouped: <?= htmlspecialchars(json_encode($groupedServices), ENT_QUOTES, 'UTF-8') ?>,
    serviceMap: <?= htmlspecialchars(json_encode($serviceMap), ENT_QUOTES, 'UTF-8') ?>,
    get currentServices() {
        return this.servicesGrouped[this.categoryId] || [];
    },
    get selectedService() {
        return this.serviceMap[this.serviceId] || null;
    },
    get calculatedCost() {
        if (!this.selectedService) return '0.00';
        let rate = parseFloat(this.selectedService.rate) || 0;
        let qty = parseInt(this.quantity) || 0;
        return ((rate * qty) / 1000).toFixed(4);
    },
    init() {
        this.$watch('categoryId', () => {
            if (this.currentServices.length > 0) {
                this.serviceId = this.currentServices[0].id;
            } else {
                this.serviceId = '';
            }
        });
        if (!this.serviceId && this.currentServices.length > 0) {
            this.serviceId = this.currentServices[0].id;
        }
    }
}">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Place a New Order</h1>
            <p class="text-slate-400 text-sm mt-1">Select a service, enter your link and quantity to dispatch instantly.</p>
        </div>
        <div class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-2 flex items-center space-x-3">
            <span class="text-xs text-slate-400">Available:</span>
            <span class="text-sm font-bold text-emerald-400"><?= formatCurrency((float)$user['balance'], $user['currency']) ?></span>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 p-4 rounded-xl text-sm flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="/user/new-order.php" class="space-y-6">
            <?= getCsrfInput() ?>

            <!-- Category Selector -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Category</label>
                <select name="category_id" x-model="categoryId"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Service Selector -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Service</label>
                <select name="service_id" x-model="serviceId" required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
                    <template x-for="s in currentServices" :key="s.id">
                        <option :value="s.id" x-text="s.name + ' - $' + parseFloat(s.rate).toFixed(2) + ' / 1k'"></option>
                    </template>
                </select>
            </div>

            <!-- Service Details & Description Box -->
            <template x-if="selectedService">
                <div class="bg-slate-950/70 border border-slate-800/80 rounded-xl p-4 text-xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Price per 1,000 units:</span>
                        <span class="font-bold text-emerald-400" x-text="'$' + parseFloat(selectedService.rate).toFixed(2)"></span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Min / Max Quantity:</span>
                        <span class="font-mono text-slate-200" x-text="parseInt(selectedService.min_quantity).toLocaleString() + ' / ' + parseInt(selectedService.max_quantity).toLocaleString()"></span>
                    </div>
                    <template x-if="selectedService.description">
                        <div class="pt-2 border-t border-slate-800 text-slate-300 whitespace-pre-wrap leading-relaxed" x-text="selectedService.description"></div>
                    </template>
                </div>
            </template>

            <!-- Target Link -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Target Link / URL / Username</label>
                <input type="text" name="link" required placeholder="https://instagram.com/username or post URL"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <!-- Quantity Input -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Quantity</label>
                <input type="number" name="quantity" required x-model="quantity"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <!-- Dynamic Charge Preview -->
            <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 flex items-center justify-between">
                <span class="text-slate-400 text-sm font-medium">Total Charge:</span>
                <span class="text-xl font-extrabold text-blue-400 font-mono" x-text="'$' + calculatedCost"></span>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3.5 px-6 rounded-xl transition shadow-lg shadow-blue-500/20">
                Submit Order
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
