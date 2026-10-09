<?php
/**
 * Mona SMM Panel v2 - User Registration
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header("Location: /user/dashboard.php");
    exit;
}

$error = null;
$refCode = cleanInput($_GET['ref'] ?? $_COOKIE['ref_code'] ?? '');
if (!empty($_GET['ref'])) {
    setcookie('ref_code', $refCode, time() + (86400 * 30), '/', '', false, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrDie();

    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (empty($username) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        $pdo = getDB();
        
        // Check uniqueness
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
        $checkStmt->execute([':u' => $username, ':e' => $email]);
        if ($checkStmt->fetch()) {
            $error = "Username or email is already registered.";
        } else {
            // Check referrer
            $referrerId = null;
            if (!empty($refCode)) {
                $refStmt = $pdo->prepare("SELECT id FROM users WHERE referral_code = :ref LIMIT 1");
                $refStmt->execute([':ref' => $refCode]);
                $referrerId = $refStmt->fetchColumn() ?: null;
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $apiKey = bin2hex(random_bytes(32));
            $userRefCode = substr(md5(uniqid($username, true)), 0, 10);

            $stmt = $pdo->prepare("
                INSERT INTO users 
                (username, email, password, role, status, api_key, referral_code, referred_by, created_at)
                VALUES (:u, :e, :p, 'user', 'active', :api, :ref, :r_by, NOW())
            ");
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':p' => $passwordHash,
                ':api' => $apiKey,
                ':ref' => $userRefCode,
                ':r_by' => $referrerId
            ]);
            $newUserId = (int)$pdo->lastInsertId();

            if ($referrerId) {
                $pdo->prepare("
                    INSERT INTO referrals (referrer_id, referred_id, commission_rate, total_earned, created_at)
                    VALUES (:r_id, :ref_id, 5.00, 0, NOW())
                ")->execute([':r_id' => $referrerId, ':ref_id' => $newUserId]);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['role'] = 'user';
            $_SESSION['username'] = $username;

            header("Location: /user/dashboard.php");
            exit;
        }
    }
}

$pageTitle = "Create Account | " . SITE_NAME;
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto my-12 bg-slate-900/90 backdrop-blur border border-slate-800 rounded-2xl p-8 shadow-2xl">
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold text-white tracking-tight">Create an Account</h1>
        <p class="text-slate-400 text-sm mt-1">Join <?= htmlspecialchars(SITE_NAME) ?> and start ordering services</p>
    </div>

    <?php if ($error): ?>
    <div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 p-4 rounded-xl text-sm mb-6 flex items-center space-x-3">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="/register.php" class="space-y-4">
        <?= getCsrfInput() ?>

        <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Username</label>
            <input type="text" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Email Address</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Password</label>
            <input type="password" name="password" required minlength="6"
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase mb-2">Confirm Password</label>
            <input type="password" name="confirm_password" required minlength="6"
                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-blue-500">
        </div>

        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-4 rounded-xl transition shadow-lg shadow-blue-500/25 mt-4">
            Register Account
        </button>
    </form>

    <div class="text-center text-xs text-slate-400 mt-6">
        Already registered? <a href="/login.php" class="text-blue-400 hover:underline font-medium">Sign in</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
