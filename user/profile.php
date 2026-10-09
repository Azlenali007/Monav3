<?php
/**
 * Mona SMM Panel v2 - User Profile & API Key Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

$user = requireLogin();
$pdo = getDB();
$pageTitle = "Account Settings | " . SITE_NAME;

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');

        // Fetch fresh password hash
        $pwdStmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
        $pwdStmt->execute([':id' => $user['id']]);
        $hash = (string)$pwdStmt->fetchColumn();

        if (!password_verify($currentPass, $hash)) {
            $error = "Incorrect current password.";
        } elseif (strlen($newPass) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($newPass !== $confirmPass) {
            $error = "New passwords do not match.";
        } else {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = :p WHERE id = :id")->execute([':p' => $newHash, ':id' => $user['id']]);
            $success = "Password changed successfully.";
        }
    } elseif ($action === 'generate_api_key') {
        $newApiKey = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE users SET api_key = :k WHERE id = :id")->execute([':k' => $newApiKey, ':id' => $user['id']]);
        $success = "New API key generated successfully.";
        $user['api_key'] = $newApiKey;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Account Settings</h1>
        <p class="text-slate-400 text-sm mt-1">Manage your credentials, preferences, and developer API key.</p>
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

    <!-- Profile Overview Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-4">
        <h2 class="text-lg font-bold text-white">Profile Details</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div class="bg-slate-950 p-4 rounded-xl border border-slate-800">
                <span class="text-xs text-slate-400 block mb-1">Username</span>
                <span class="font-bold text-white"><?= htmlspecialchars($user['username']) ?></span>
            </div>
            <div class="bg-slate-950 p-4 rounded-xl border border-slate-800">
                <span class="text-xs text-slate-400 block mb-1">Email Address</span>
                <span class="font-bold text-white"><?= htmlspecialchars($user['email']) ?></span>
            </div>
        </div>
    </div>

    <!-- API Key Section -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-4">
        <h2 class="text-lg font-bold text-white">Developer API Key</h2>
        <p class="text-slate-400 text-xs">Use this secret key to interact with our platform via the standard SMM API v2 endpoint (<code><?= SITE_URL ?>/api/v2.php</code>).</p>
        
        <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-3">
            <code class="font-mono text-sm text-blue-400 break-all select-all"><?= htmlspecialchars($user['api_key'] ?? 'No API Key Generated') ?></code>
        </div>

        <form method="POST" action="/user/profile.php" onsubmit="return confirm('Regenerating your key will invalidate existing integrations. Continue?');">
            <?= getCsrfInput() ?>
            <input type="hidden" name="action" value="generate_api_key">
            <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold px-4 py-2.5 rounded-xl transition border border-slate-700">
                Regenerate API Key
            </button>
        </form>
    </div>

    <!-- Change Password Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-4">
        <h2 class="text-lg font-bold text-white">Security & Password</h2>
        <form method="POST" action="/user/profile.php" class="space-y-4 max-w-md">
            <?= getCsrfInput() ?>
            <input type="hidden" name="action" value="change_password">

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Current Password</label>
                <input type="password" name="current_password" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">New Password</label>
                <input type="password" name="new_password" required minlength="6"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="6"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
            </div>

            <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-semibold py-2.5 px-6 rounded-xl transition text-sm">
                Update Password
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
