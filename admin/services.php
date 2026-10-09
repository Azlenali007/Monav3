<?php
/**
 * Mona SMM Panel v2 - Admin Services CRUD
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Services Management | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_service') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $name = cleanInput($_POST['name'] ?? '');
        $rate = (float)($_POST['rate'] ?? 0);
        $min = (int)($_POST['min_quantity'] ?? 10);
        $max = (int)($_POST['max_quantity'] ?? 10000);
        $desc = cleanInput($_POST['description'] ?? '');
        $provId = (int)($_POST['provider_id'] ?? 0) ?: null;
        $psId = cleanInput($_POST['provider_service_id'] ?? '') ?: null;

        if ($catId <= 0 || empty($name) || $rate < 0) {
            $err = "Category, Service Name, and valid Rate are required.";
        } else {
            $pdo->prepare("
                INSERT INTO services 
                (category_id, provider_id, provider_service_id, name, rate, original_rate, min_quantity, max_quantity, description, status, created_at)
                VALUES (:cid, :pid, :psid, :name, :r, :r, :min, :max, :desc, 1, NOW())
            ")->execute([
                ':cid' => $catId,
                ':pid' => $provId,
                ':psid' => $psId,
                ':name' => $name,
                ':r' => $rate,
                ':min' => $min,
                ':max' => $max,
                ':desc' => $desc
            ]);
            $msg = "Service created successfully.";
        }
    } elseif ($action === 'delete_service') {
        $id = (int)($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM services WHERE id = :id")->execute([':id' => $id]);
            $msg = "Service #{$id} deleted successfully.";
        }
    }
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$providers = $pdo->query("SELECT * FROM providers WHERE status = 1")->fetchAll();
$services = $pdo->query("
    SELECT s.*, c.name as category_name, p.name as provider_name 
    FROM services s 
    LEFT JOIN categories c ON s.category_id = c.id 
    LEFT JOIN providers p ON s.provider_id = p.id 
    ORDER BY s.id DESC LIMIT 100
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Services Management</h1>
            <p class="text-slate-400 text-sm mt-1">Configure pricing, limits, descriptions, and upstream provider mapping.</p>
        </div>
        <a href="/admin/import-services.php" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-4 py-2 rounded-xl transition">
            Import from Provider &rarr;
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

    <!-- Add Service Form -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
        <h2 class="text-lg font-bold text-white mb-4">Create New Service</h2>
        <form method="POST" action="/admin/services.php" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <?= getCsrfInput() ?>
            <input type="hidden" name="action" value="create_service">

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Category</label>
                <select name="category_id" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Service Name</label>
                <input type="text" name="name" required placeholder="e.g. Instagram Followers [Fast]"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Rate / 1k ($)</label>
                <input type="number" step="0.0001" name="rate" required placeholder="1.50"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Min Quantity</label>
                <input type="number" name="min_quantity" value="10" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Max Quantity</label>
                <input type="number" name="max_quantity" value="10000" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Provider Mapping (Optional)</label>
                <select name="provider_id" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">None (Manual)</option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-3">
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Description / Notes</label>
                <textarea name="description" rows="2" placeholder="Service description, speed, refill details..."
                          class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-white text-sm focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="sm:col-span-3 flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 px-6 rounded-xl transition text-sm">
                    Add Service
                </button>
            </div>
        </form>
    </div>

    <!-- Services Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">Category</th>
                        <th class="p-4">Name</th>
                        <th class="p-4">Rate / 1k</th>
                        <th class="p-4">Limits</th>
                        <th class="p-4">Provider</th>
                        <th class="p-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    <?php foreach ($services as $s): ?>
                    <tr class="hover:bg-slate-800/30 transition">
                        <td class="p-4 font-mono text-xs text-slate-400">#<?= $s['id'] ?></td>
                        <td class="p-4 text-slate-300 font-medium"><?= htmlspecialchars((string)($s['category_name'] ?? '-')) ?></td>
                        <td class="p-4 font-bold text-white"><?= htmlspecialchars($s['name']) ?></td>
                        <td class="p-4 font-mono font-bold text-emerald-400">$<?= number_format((float)$s['rate'], 4) ?></td>
                        <td class="p-4 font-mono text-xs text-slate-400"><?= number_format($s['min_quantity']) ?> / <?= number_format($s['max_quantity']) ?></td>
                        <td class="p-4 text-xs text-slate-400"><?= htmlspecialchars((string)($s['provider_name'] ?? 'Manual')) ?></td>
                        <td class="p-4 text-right">
                            <form method="POST" action="/admin/services.php" onsubmit="return confirm('Delete this service?');" class="inline-block">
                                <?= getCsrfInput() ?>
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="text-rose-400 hover:underline text-xs font-semibold">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
