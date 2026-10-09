<?php
/**
 * Mona SMM Panel v2 - Admin Announcements & Broadcasts
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Announcements | Admin Control Panel";

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_announcement') {
        $title = cleanInput($_POST['title'] ?? '');
        $content = trim((string)($_POST['content'] ?? ''));
        $type = in_array($_POST['type'] ?? '', ['info', 'warning', 'success', 'danger']) ? $_POST['type'] : 'info';

        if (empty($title) || empty($content)) {
            $err = "Title and content are required.";
        } else {
            $pdo->prepare("INSERT INTO announcements (title, content, type, status, created_at) VALUES (:t, :c, :type, 1, NOW())")
                ->execute([':t' => $title, ':c' => $content, ':type' => $type]);
            $msg = "Announcement published successfully.";
        }
    } elseif ($action === 'delete_announcement') {
        $id = (int)($_POST['announcement_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM announcements WHERE id = :id")->execute([':id' => $id]);
            $msg = "Announcement deleted.";
        }
    }
}

$announcements = $pdo->query("SELECT * FROM announcements ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Announcements & Broadcasts</h1>
            <p class="text-slate-400 text-sm mt-1">Post updates, maintenance notices, and alerts visible on user dashboards.</p>
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
        <!-- New Announcement Form -->
        <div class="lg:col-span-4 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-lg font-bold text-white">Create Announcement</h2>
            <form method="POST" action="/admin/announcements.php" class="space-y-4">
                <?= getCsrfInput() ?>
                <input type="hidden" name="action" value="create_announcement">

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Title</label>
                    <input type="text" name="title" required placeholder="e.g. Scheduled Maintenance Tonight"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Banner Type</label>
                    <select name="type" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none">
                        <option value="info">Info (Blue)</option>
                        <option value="warning">Warning (Amber)</option>
                        <option value="danger">Critical / Alert (Red)</option>
                        <option value="success">Success (Green)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Message Content</label>
                    <textarea name="content" rows="4" required placeholder="Announcement message..."
                              class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-white text-sm focus:outline-none"></textarea>
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 rounded-xl transition text-sm">
                    Publish Announcement
                </button>
            </form>
        </div>

        <!-- Announcements List -->
        <div class="lg:col-span-8 bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">Type</th>
                            <th class="p-4">Title</th>
                            <th class="p-4">Date</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($announcements as $a): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-[11px] font-bold uppercase <?= $a['type'] === 'danger' ? 'bg-rose-500/20 text-rose-400' : 'bg-blue-500/20 text-blue-400' ?>">
                                    <?= htmlspecialchars($a['type']) ?>
                                </span>
                            </td>
                            <td class="p-4">
                                <span class="font-bold text-white block"><?= htmlspecialchars($a['title']) ?></span>
                                <span class="text-xs text-slate-400"><?= htmlspecialchars($a['content']) ?></span>
                            </td>
                            <td class="p-4 text-xs text-slate-400"><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
                            <td class="p-4 text-right">
                                <form method="POST" action="/admin/announcements.php" onsubmit="return confirm('Delete announcement?');" class="inline-block">
                                    <?= getCsrfInput() ?>
                                    <input type="hidden" name="action" value="delete_announcement">
                                    <input type="hidden" name="announcement_id" value="<?= $a['id'] ?>">
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
