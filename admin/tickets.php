<?php
/**
 * Mona SMM Panel v2 - Admin Support Tickets Helpdesk
 * Threaded replies, status management, and CSRF protection
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = requireAdmin();
$pdo = getDB();
$pageTitle = "Support Helpdesk | Admin Control Panel";

$viewTicketId = (int)($_GET['id'] ?? 0);
$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';
    $ticketId = (int)($_POST['ticket_id'] ?? 0);

    if ($action === 'reply_ticket' && $ticketId > 0) {
        $replyText = trim((string)($_POST['message'] ?? ''));
        if (empty($replyText)) {
            $err = "Reply message cannot be empty.";
        } else {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at)
                VALUES (:tid, :uid, :msg, 1, NOW())
            ")->execute([
                ':tid' => $ticketId,
                ':uid' => $admin['id'],
                ':msg' => $replyText
            ]);

            $pdo->prepare("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $ticketId]);

            $pdo->commit();
            $msg = "Reply posted successfully.";
            $viewTicketId = $ticketId;
        }
    } elseif ($action === 'close_ticket' && $ticketId > 0) {
        $pdo->prepare("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = :id")->execute([':id' => $ticketId]);
        $msg = "Ticket #{$ticketId} closed.";
        $viewTicketId = $ticketId;
    }
}

// Single ticket view
$activeTicket = null;
$messages = [];
if ($viewTicketId > 0) {
    $tStmt = $pdo->prepare("
        SELECT t.*, u.username, u.email 
        FROM tickets t 
        LEFT JOIN users u ON t.user_id = u.id 
        WHERE t.id = :id LIMIT 1
    ");
    $tStmt->execute([':id' => $viewTicketId]);
    $activeTicket = $tStmt->fetch();

    if ($activeTicket) {
        $mStmt = $pdo->prepare("
            SELECT tm.*, u.username 
            FROM ticket_messages tm 
            LEFT JOIN users u ON tm.user_id = u.id 
            WHERE tm.ticket_id = :tid 
            ORDER BY tm.id ASC
        ");
        $mStmt->execute([':tid' => $viewTicketId]);
        $messages = $mStmt->fetchAll();
    }
}

// All tickets list
$allTickets = $pdo->query("
    SELECT t.*, u.username 
    FROM tickets t 
    LEFT JOIN users u ON t.user_id = u.id 
    ORDER BY CASE WHEN t.status IN ('open', 'user_reply') THEN 1 ELSE 2 END, t.id DESC 
    LIMIT 50
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Support Tickets Helpdesk</h1>
            <p class="text-slate-400 text-sm mt-1">Review inquiries, respond to clients, and manage ticket lifecycles.</p>
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

    <?php if ($activeTicket): ?>
        <!-- Conversation Thread -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-slate-800 gap-4">
                <div>
                    <span class="text-xs font-mono text-slate-500">Ticket #<?= $activeTicket['id'] ?> | User: <?= htmlspecialchars($activeTicket['username']) ?> (<?= htmlspecialchars($activeTicket['email']) ?>)</span>
                    <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($activeTicket['subject']) ?></h2>
                </div>
                <div class="flex items-center space-x-3">
                    <form method="POST" action="/admin/tickets.php?id=<?= $activeTicket['id'] ?>" class="inline-block">
                        <?= getCsrfInput() ?>
                        <input type="hidden" name="action" value="close_ticket">
                        <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs px-3 py-1.5 rounded-lg border border-slate-700">
                            Close Ticket
                        </button>
                    </form>
                    <a href="/admin/tickets.php" class="text-xs text-slate-400 hover:text-white underline">Back to List</a>
                </div>
            </div>

            <!-- Messages Stream -->
            <div class="space-y-4">
                <?php foreach ($messages as $msgItem): 
                    $isAdmin = !empty($msgItem['is_admin']);
                ?>
                <div class="p-4 rounded-xl border <?= $isAdmin ? 'bg-blue-950/40 border-blue-800/40' : 'bg-slate-950 border-slate-800' ?>">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-bold text-sm <?= $isAdmin ? 'text-blue-400' : 'text-white' ?>">
                            <?= $isAdmin ? 'Administrator' : htmlspecialchars((string)($msgItem['username'] ?? 'Client')) ?>
                        </span>
                        <span class="text-xs text-slate-500"><?= date('M d, H:i', strtotime($msgItem['created_at'])) ?></span>
                    </div>
                    <div class="text-slate-300 text-sm whitespace-pre-wrap leading-relaxed"><?= nl2br(htmlspecialchars($msgItem['message'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Admin Reply Box -->
            <form method="POST" action="/admin/tickets.php?id=<?= $activeTicket['id'] ?>" class="pt-4 border-t border-slate-800 space-y-4">
                <?= getCsrfInput() ?>
                <input type="hidden" name="action" value="reply_ticket">
                <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">

                <textarea name="message" rows="4" required placeholder="Type administrator reply..."
                          class="w-full bg-slate-950 border border-slate-800 rounded-xl p-4 text-white text-sm focus:outline-none focus:border-blue-500"></textarea>

                <div class="flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 px-6 rounded-xl transition text-sm">
                        Submit Official Reply
                    </button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Tickets List Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-950 text-xs uppercase text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="p-4">ID</th>
                            <th class="p-4">User</th>
                            <th class="p-4">Subject</th>
                            <th class="p-4">Priority</th>
                            <th class="p-4">Status</th>
                            <th class="p-4">Created</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <?php foreach ($allTickets as $t): ?>
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="p-4 font-mono text-xs text-slate-400">#<?= $t['id'] ?></td>
                            <td class="p-4 font-bold text-white"><?= htmlspecialchars((string)($t['username'] ?? 'User #' . $t['user_id'])) ?></td>
                            <td class="p-4 text-slate-200 font-medium max-w-xs truncate"><?= htmlspecialchars($t['subject']) ?></td>
                            <td class="p-4">
                                <span class="text-xs uppercase font-bold <?= $t['priority'] === 'high' ? 'text-rose-400' : 'text-slate-400' ?>">
                                    <?= htmlspecialchars($t['priority']) ?>
                                </span>
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold capitalize <?= in_array($t['status'], ['open', 'user_reply']) ? 'bg-amber-500/20 text-amber-300' : ($t['status'] === 'answered' ? 'bg-blue-500/20 text-blue-400' : 'bg-slate-800 text-slate-400') ?>">
                                    <?= str_replace('_', ' ', $t['status']) ?>
                                </span>
                            </td>
                            <td class="p-4 text-xs text-slate-400"><?= date('M d, H:i', strtotime($t['created_at'])) ?></td>
                            <td class="p-4 text-right">
                                <a href="/admin/tickets.php?id=<?= $t['id'] ?>" class="text-xs font-semibold text-blue-400 hover:underline">
                                    Manage &rarr;
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
