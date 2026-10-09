<?php
/**
 * Mona SMM Panel v2 - Customer Support Ticket System
 * Strictly enforces CSRF on both new tickets and threaded replies
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Support Tickets | " . SITE_NAME;

$viewTicketId = (int)($_GET['id'] ?? 0);
$error = null;
$success = null;

// Handle Actions (Create Ticket or Post Message)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // FIX: Enforce CSRF validation across all actions
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $subject = cleanInput($_POST['subject'] ?? '');
        $message = trim((string)($_POST['message'] ?? ''));
        $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high']) ? $_POST['priority'] : 'medium';

        if (empty($subject) || empty($message)) {
            $error = "Please provide both a subject and a message.";
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO tickets (user_id, subject, priority, status, created_at)
                VALUES (:uid, :sub, :prio, 'open', NOW())
            ");
            $stmt->execute([
                ':uid' => $user['id'],
                ':sub' => $subject,
                ':prio' => $priority
            ]);
            $ticketId = (int)$pdo->lastInsertId();

            $msgStmt = $pdo->prepare("
                INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at)
                VALUES (:tid, :uid, :msg, 0, NOW())
            ");
            $msgStmt->execute([
                ':tid' => $ticketId,
                ':uid' => $user['id'],
                ':msg' => $message
            ]);

            $pdo->commit();
            $success = "Support ticket #{$ticketId} opened successfully.";
            $viewTicketId = $ticketId;
        }
    } elseif ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));

        if ($ticketId <= 0 || empty($message)) {
            $error = "Reply message cannot be empty.";
        } else {
            // Verify ticket ownership
            $checkStmt = $pdo->prepare("SELECT id, status FROM tickets WHERE id = :id AND user_id = :uid LIMIT 1");
            $checkStmt->execute([':id' => $ticketId, ':uid' => $user['id']]);
            $ticket = $checkStmt->fetch();

            if (!$ticket) {
                $error = "Ticket not found or access denied.";
            } elseif ($ticket['status'] === 'closed') {
                $error = "This ticket has been closed and cannot accept new replies.";
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at)
                    VALUES (:tid, :uid, :msg, 0, NOW())
                ")->execute([
                    ':tid' => $ticketId,
                    ':uid' => $user['id'],
                    ':msg' => $message
                ]);

                // Update ticket status to user_reply
                $pdo->prepare("UPDATE tickets SET status = 'user_reply', updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $ticketId]);

                $pdo->commit();
                $success = "Reply posted successfully.";
                $viewTicketId = $ticketId;
            }
        }
    }
}

// Fetch single ticket details if viewing
$activeTicket = null;
$messages = [];
if ($viewTicketId > 0) {
    $tStmt = $pdo->prepare("SELECT * FROM tickets WHERE id = :id AND user_id = :uid LIMIT 1");
    $tStmt->execute([':id' => $viewTicketId, ':uid' => $user['id']]);
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

// List user tickets
$ticketsStmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = :uid ORDER BY id DESC LIMIT 20");
$ticketsStmt->execute([':uid' => $user['id']]);
$userTickets = $ticketsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Customer Support Helpdesk</h1>
            <p class="text-slate-400 text-sm mt-1">Submit inquiries regarding orders, payments, or services.</p>
        </div>
        <a href="/user/tickets.php" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm px-4 py-2 rounded-xl transition">
            + Open New Ticket
        </a>
    </div>

    <?php if ($success): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($activeTicket): ?>
        <!-- Conversation Thread View -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-slate-800 gap-4">
                <div>
                    <span class="text-xs font-mono text-slate-500">Ticket #<?= $activeTicket['id'] ?></span>
                    <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($activeTicket['subject']) ?></h2>
                </div>
                <div class="flex items-center space-x-3">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold capitalize <?= $activeTicket['status'] === 'closed' ? 'bg-slate-800 text-slate-400' : 'bg-blue-500/20 text-blue-400' ?>">
                        <?= str_replace('_', ' ', $activeTicket['status']) ?>
                    </span>
                    <a href="/user/tickets.php" class="text-xs text-slate-400 hover:text-white underline">Back to List</a>
                </div>
            </div>

            <!-- Messages Stream -->
            <div class="space-y-4">
                <?php foreach ($messages as $msg): 
                    $isAdmin = !empty($msg['is_admin']);
                ?>
                <div class="p-4 rounded-xl border <?= $isAdmin ? 'bg-blue-950/30 border-blue-800/40' : 'bg-slate-950 border-slate-800' ?>">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-bold text-sm <?= $isAdmin ? 'text-blue-400' : 'text-white' ?>">
                            <?= $isAdmin ? 'Support Staff' : htmlspecialchars((string)($msg['username'] ?? 'You')) ?>
                        </span>
                        <span class="text-xs text-slate-500"><?= date('M d, H:i', strtotime($msg['created_at'])) ?></span>
                    </div>
                    <div class="text-slate-300 text-sm whitespace-pre-wrap leading-relaxed"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Reply Box -->
            <?php if ($activeTicket['status'] !== 'closed'): ?>
            <form method="POST" action="/user/tickets.php?id=<?= $activeTicket['id'] ?>" class="pt-4 border-t border-slate-800 space-y-4">
                <?= getCsrfInput() ?>
                <input type="hidden" name="action" value="reply_ticket">
                <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">

                <textarea name="message" rows="4" required placeholder="Type your reply here..."
                          class="w-full bg-slate-950 border border-slate-800 rounded-xl p-4 text-white text-sm focus:outline-none focus:border-blue-500"></textarea>

                <div class="flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 px-6 rounded-xl transition text-sm">
                        Post Reply
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- New Ticket Form & History Split -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- New Ticket Form -->
            <div class="lg:col-span-5 bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <h2 class="text-lg font-bold text-white">Create New Inquiry</h2>
                <form method="POST" action="/user/tickets.php" class="space-y-4">
                    <?= getCsrfInput() ?>
                    <input type="hidden" name="action" value="create_ticket">

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Subject</label>
                        <input type="text" name="subject" required placeholder="e.g. Order #1234 refilling request"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Priority</label>
                        <select name="priority" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Message</label>
                        <textarea name="message" rows="5" required placeholder="Detailed description of your issue..."
                                  class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-white text-sm focus:outline-none focus:border-blue-500"></textarea>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 rounded-xl transition text-sm">
                        Submit Ticket
                    </button>
                </form>
            </div>

            <!-- Existing Tickets Table -->
            <div class="lg:col-span-7 bg-slate-900 border border-slate-800 rounded-2xl p-6">
                <h2 class="text-lg font-bold text-white mb-4">Your Recent Tickets</h2>
                <?php if (empty($userTickets)): ?>
                    <p class="text-slate-500 text-sm text-center py-8">No support tickets found.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-slate-400 border-b border-slate-800">
                                <tr>
                                    <th class="pb-3">ID</th>
                                    <th class="pb-3">Subject</th>
                                    <th class="pb-3">Status</th>
                                    <th class="pb-3">Date</th>
                                    <th class="pb-3 text-right">View</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <?php foreach ($userTickets as $t): ?>
                                <tr>
                                    <td class="py-3 font-mono text-xs text-slate-400">#<?= $t['id'] ?></td>
                                    <td class="py-3 font-medium text-white max-w-xs truncate"><?= htmlspecialchars($t['subject']) ?></td>
                                    <td class="py-3">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold capitalize <?= $t['status'] === 'closed' ? 'bg-slate-800 text-slate-400' : 'bg-blue-500/20 text-blue-400' ?>">
                                            <?= str_replace('_', ' ', $t['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 text-xs text-slate-400"><?= date('M d', strtotime($t['created_at'])) ?></td>
                                    <td class="py-3 text-right">
                                        <a href="/user/tickets.php?id=<?= $t['id'] ?>" class="text-blue-400 hover:underline font-semibold text-xs">
                                            Open &rarr;
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
