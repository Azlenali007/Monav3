<?php
/**
 * Mona SMM Panel v2 - CSRF Protection Module
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function getCsrfToken(): string {
    return generateCsrfToken();
}

function getCsrfInput(): string {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function validateCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function verifyCsrfOrDie(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired CSRF token. Please refresh.']);
            exit;
        }
        die("Security validation failed (Invalid CSRF Token). Please return and refresh the page.");
    }
}
