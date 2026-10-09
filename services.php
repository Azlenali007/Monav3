<?php
/**
 * Mona SMM Panel v2 - Public Services Catalog
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();

// Fetch categories and active services
$categories = $pdo->query("SELECT * FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$servicesStmt = $pdo->query("
    SELECT s.*, c.name as category_name 
    FROM services s 
    LEFT JOIN categories c ON s.category_id = c.id 
    WHERE s.status = 1 
    ORDER BY c.sort_order ASC, s.sort_order ASC, s.id ASC
");
$services = $servicesStmt->fetchAll();

// Group services by category
$grouped = [];
foreach ($services as $s) {
    $cName = $s['category_name'] ?: 'General Services';
    $grouped[$cName][] = $s;
}

$pageTitle = "Services & Pricing | " . SITE_NAME;
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-8" x-data="{ search: '', filterCategory: '' }">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-white tracking-tight">Services & Wholesale Rates</h1>
            <p class="text-slate-400 text-sm mt-1">Live service pricing per 1,000 units. Transparent, competitive, and instantly dispatched.</p>
        </div>
        <div class="flex items-center space-x-3">
            <input type="text" placeholder="Search services..." x-model="search"
                   class="bg-slate-900 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500 w-64">
        </div>
    </div>

    <!-- Category Filter Buttons -->
    <div class="flex items-center space-x-2 overflow-x-auto pb-2">
        <button @click="filterCategory = ''" :class="filterCategory === '' ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:text-white'"
                class="px-4 py-2 rounded-xl text-xs font-semibold uppercase tracking-wider transition border border-slate-800 flex-shrink-0">
            All Categories (<?= count($services) ?>)
        </button>
        <?php foreach ($categories as $cat): ?>
        <button @click="filterCategory = '<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>'" 
                :class="filterCategory === '<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>' ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:text-white'"
                class="px-4 py-2 rounded-xl text-xs font-semibold uppercase tracking-wider transition border border-slate-800 flex-shrink-0">
            <?= htmlspecialchars($cat['name']) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Services Group List -->
    <div class="space-y-8">
        <?php foreach ($grouped as $catName => $items): ?>
        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl"
             x-show="(filterCategory === '' || filterCategory === '<?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>')">
            <div class="bg-slate-950/70 border-b border-slate-800 px-6 py-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-white flex items-center space-x-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                    <span><?= htmlspecialchars($catName) ?></span>
                </h2>
                <span class="text-xs text-slate-400 font-medium"><?= count($items) ?> services</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-400 border-b border-slate-800/80 bg-slate-900/50">
                        <tr>
                            <th class="p-4 w-16">ID</th>
                            <th class="p-4">Service Name</th>
                            <th class="p-4 w-32">Rate / 1k</th>
                            <th class="p-4 w-32">Min / Max</th>
                            <th class="p-4 w-28 text-center">Drip-Feed</th>
                            <th class="p-4 w-28 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($items as $s): ?>
                        <tr class="hover:bg-slate-800/40 transition" 
                            x-show="search === '' || '<?= strtolower(addslashes($s['name'])) ?>'.includes(search.toLowerCase()) || '<?= $s['id'] ?>'.includes(search)">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $s['id'] ?></td>
                            <td class="p-4">
                                <span class="font-medium text-white block"><?= htmlspecialchars($s['name']) ?></span>
                                <?php if (!empty($s['description'])): ?>
                                    <p class="text-xs text-slate-400 mt-1 max-w-xl line-clamp-1"><?= htmlspecialchars($s['description']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 font-bold text-emerald-400"><?= formatCurrency((float)$s['rate'], 'USD') ?></td>
                            <td class="p-4 text-xs text-slate-300 font-mono"><?= number_format($s['min_quantity']) ?> / <?= number_format($s['max_quantity']) ?></td>
                            <td class="p-4 text-center">
                                <?php if (!empty($s['drip_feed'])): ?>
                                    <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-blue-500/20 text-blue-400">Yes</span>
                                <?php else: ?>
                                    <span class="text-slate-600 text-xs">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-right">
                                <a href="/user/new-order.php?service=<?= $s['id'] ?>" class="text-xs font-semibold text-blue-400 hover:text-blue-300 hover:underline">
                                    Order &rarr;
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
