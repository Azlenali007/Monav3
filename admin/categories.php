<?php
/**
 * Mona SMM Panel v2 - Admin Category Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Categories | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_category') {
        $name = cleanInput($_POST['name'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            $err = "Category name is required.";
        } else {
            $pdo->prepare("INSERT INTO categories (name, sort_order, status, created_at) VALUES (:n, :s, 1, NOW())")
                ->execute([':n' => $name, ':s' => $sort]);
            $msg = "Category created successfully.";
        }
    } elseif ($action === 'delete_category') {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $id]);
            $msg = "Category deleted.";
        }
    }
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Service Categories</h1>
            <p class="text-slate-400 text-sm mt-1">Organize your services catalog into intuitive groupings.</p>
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

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Add Category Form -->
        <div class="lg:col-span-4 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-lg font-bold text-white">Create Category</h2>
            <form method="POST" action="/admin/categories.php" class="space-y-4">
                <?= getCsrfInput() ?>
                <input type="hidden" name="action" value="create_category">

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Category Name</label>
                    <input type="text" name="name" required placeholder="e.g. Instagram Followers"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Sort Order</label>
                    <input type="number" name="sort_order" value="0"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition text-sm">
                    Save Category
                </button>
            </form>
        </div>

        <!-- Categories List -->
        <div class="lg:col-span-8 bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">Sort</th>
                            <th class="p-4">Category Name</th>
                            <th class="p-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($categories as $c): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400"><?= $c['sort_order'] ?></td>
                            <td class="p-4 font-bold text-white"><?= htmlspecialchars($c['name']) ?></td>
                            <td class="p-4 text-right">
                                <form method="POST" action="/admin/categories.php" onsubmit="return confirm('Delete category?');" class="inline-block">
                                    <?= getCsrfInput() ?>
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
