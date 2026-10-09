<?php
/**
 * Mona SMM Panel v2 - Admin Service Importer
 * Bulk import services from upstream SMM providers with custom margin markup
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Import Services | Admin Control Panel";

$providers = $pdo->query("SELECT * FROM providers WHERE status = 1")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();

$selectedProviderId = (int)($_GET['provider_id'] ?? ($_POST['provider_id'] ?? 0));
$remoteServices = [];
$msg = null;
$err = null;

// Handle Import Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'do_import') {
    verifyCsrfOrDie();

    $importedServices = $_POST['services'] ?? [];
    $marginPercent = (float)($_POST['margin_percentage'] ?? 20.0);
    $defaultCategoryId = (int)($_POST['category_id'] ?? 0);

    if (empty($importedServices)) {
        $err = "No services were selected for import.";
    } elseif ($defaultCategoryId <= 0) {
        $err = "Please select a target category.";
    } else {
        $importedCount = 0;
        foreach ($importedServices as $sDataJson) {
            $s = json_decode($sDataJson, true);
            if (!is_array($s) || empty($s['service'])) continue;

            $pServiceId = (string)$s['service'];
            $name = cleanInput($s['name'] ?? 'Unnamed Service');
            $type = cleanInput($s['type'] ?? 'Default');
            $origRate = (float)($s['rate'] ?? 0);
            $retailRate = round($origRate * (1 + ($marginPercent / 100)), 4);
            $min = (int)($s['min'] ?? 10);
            $max = (int)($s['max'] ?? 10000);
            $dripFeed = !empty($s['dripfeed']) ? 1 : 0;

            // Check if already imported
            $checkStmt = $pdo->prepare("SELECT id FROM services WHERE provider_id = :pid AND provider_service_id = :psid LIMIT 1");
            $checkStmt->execute([':pid' => $selectedProviderId, ':psid' => $pServiceId]);
            $exists = $checkStmt->fetchColumn();

            if ($exists) {
                // Update pricing and limits
                $pdo->prepare("
                    UPDATE services 
                    SET rate = :r, original_rate = :or, min_quantity = :min, max_quantity = :max, status = 1 
                    WHERE id = :id
                ")->execute([
                    ':r' => $retailRate,
                    ':or' => $origRate,
                    ':min' => $min,
                    ':max' => $max,
                    ':id' => $exists
                ]);
            } else {
                // Insert new service
                $pdo->prepare("
                    INSERT INTO services 
                    (category_id, provider_id, provider_service_id, name, type, rate, original_rate, min_quantity, max_quantity, drip_feed, status, created_at)
                    VALUES (:cid, :pid, :psid, :name, :type, :r, :or, :min, :max, :df, 1, NOW())
                ")->execute([
                    ':cid' => $defaultCategoryId,
                    ':pid' => $selectedProviderId,
                    ':psid' => $pServiceId,
                    ':name' => $name,
                    ':type' => $type,
                    ':r' => $retailRate,
                    ':or' => $origRate,
                    ':min' => $min,
                    ':max' => $max,
                    ':df' => $dripFeed
                ]);
            }
            $importedCount++;
        }

        $msg = "Successfully imported/updated {$importedCount} services with +{$marginPercent}% profit margin.";
    }
}

// Fetch remote services if provider selected
if ($selectedProviderId > 0) {
    $provStmt = $pdo->prepare("SELECT * FROM providers WHERE id = :id");
    $provStmt->execute([':id' => $selectedProviderId]);
    $prov = $provStmt->fetch();

    if ($prov) {
        $res = callProviderApi($prov, ['action' => 'services']);
        if (is_array($res) && empty($res['error'])) {
            $remoteServices = $res;
        } else {
            $err = "Failed to load services from provider: " . ($res['error'] ?? 'Unknown error');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Import Services from Provider</h1>
            <p class="text-slate-400 text-sm mt-1">Connect to an upstream catalog and batch import services with automated margin calculation.</p>
        </div>
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

    <!-- Step 1: Select Provider -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
        <form method="GET" action="/admin/import-services.php" class="flex flex-col sm:flex-row items-end gap-4 max-w-xl">
            <div class="flex-1 w-full">
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Select Provider</label>
                <select name="provider_id" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">-- Choose an active provider --</option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $selectedProviderId === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 px-6 rounded-xl transition text-sm">
                Fetch Services
            </button>
        </form>
    </div>

    <!-- Step 2: Service Import Table -->
    <?php if (!empty($remoteServices)): ?>
    <form method="POST" action="/admin/import-services.php" class="space-y-6">
        <?= getCsrfInput() ?>
        <input type="hidden" name="action" value="do_import">
        <input type="hidden" name="provider_id" value="<?= $selectedProviderId ?>">

        <!-- Batch Settings Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Target Category</label>
                <select name="category_id" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Profit Margin (%)</label>
                <input type="number" step="1" name="margin_percentage" value="25" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 px-6 rounded-xl transition text-sm">
                    Import Selected (Batch)
                </button>
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4 w-12 text-center">
                                <input type="checkbox" onclick="document.querySelectorAll('.srv-cb').forEach(c => c.checked = this.checked)" class="rounded">
                            </th>
                            <th class="p-4 w-16">ID</th>
                            <th class="p-4">Service Name</th>
                            <th class="p-4">Type</th>
                            <th class="p-4">Provider Rate / 1k</th>
                            <th class="p-4">Min / Max</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($remoteServices as $s): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 text-center">
                                <input type="checkbox" name="services[]" value="<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>" class="srv-cb rounded">
                            </td>
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= htmlspecialchars((string)($s['service'] ?? '')) ?></td>
                            <td class="p-4 font-bold text-white max-w-md truncate"><?= htmlspecialchars((string)($s['name'] ?? '')) ?></td>
                            <td class="p-4 text-xs text-slate-400"><?= htmlspecialchars((string)($s['type'] ?? 'Default')) ?></td>
                            <td class="p-4 font-mono font-bold text-emerald-400">$<?= number_format((float)($s['rate'] ?? 0), 4) ?></td>
                            <td class="p-4 font-mono text-xs text-slate-300"><?= number_format((int)($s['min'] ?? 0)) ?> / <?= number_format((int)($s['max'] ?? 0)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
