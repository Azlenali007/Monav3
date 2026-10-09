<?php
/**
 * Mona SMM Panel v2 - Authentication & RBAC Engine
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null) {
        return $cachedUser;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, email, balance, spent, role, status, api_key, referral_code, currency, custom_rates, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        // Invalidate session if user account was deleted or banned
        session_unset();
        session_destroy();
        return null;
    }

    $cachedUser = $user;
    return $cachedUser;
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) {
        $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '/user/dashboard.php');
        header("Location: /login.php?redirect=" . $returnUrl);
        exit;
    }
    return $user;
}

function requireAdmin(): array {
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die("Access denied. Administrator privileges required.");
    }
    return $user;
}
