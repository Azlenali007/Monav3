<?php
/**
 * Mona SMM Panel v2 - Secure Login Controller
 * Enforces session regeneration, CSRF mitigation, and strict password hashing
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header("Location: " . (isAdmin() ? "/admin/dashboard.php" : "/user/dashboard.php"));
    exit;
}

$error = null;
$redirect = cleanInput($_GET['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Please enter both username/email and password.";
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = :u OR email = :e) LIMIT 1");
        $stmt->execute([':u' => $username, ':e' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = "Invalid username/email or password.";
        } elseif ($user['status'] !== 'active') {
            $error = "Your account is currently " . htmlspecialchars($user['status']) . ". Please contact support.";
        } else {
            // FIX: Regenerate session ID to prevent Session Fixation attacks
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];

            // Safe redirection
            if (!empty($redirect) && str_starts_with($redirect, '/')) {
                header("Location: " . $redirect);
            } else {
                header("Location: " . ($user['role'] === 'admin' ? "/admin/dashboard.php" : "/user/dashboard.php"));
            }
            exit;
        }
    }
}

$pageTitle = "Sign In | " . SITE_NAME;
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto my-12 bg-slate-900/90 backdrop-blur border border-slate-800 rounded-2xl p-8 shadow-2xl">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight">Welcome Back</h1>
        <p class="text-slate-400 text-sm mt-1">Sign in to your <?= htmlspecialchars(SITE_NAME) ?> account</p>
    </div>

    <?php if ($error): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm mb-6 flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login.php<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>" class="space-y-4">
        <?= getCsrfInput() ?>

        <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Username or Email</label>
            <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
        </div>

        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="block text-xs font-semibold text-slate-300 uppercase">Password</label>
            </div>
            <input type="password" name="password" required
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
        </div>

        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-4 rounded-xl transition shadow-lg shadow-blue-500/25 mt-2">
            Sign In
        </button>
    </form>

    <div class="text-center text-xs text-slate-400 mt-6">
        Don't have an account? <a href="/register.php" class="text-blue-400 hover:underline font-medium">Create one now</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
