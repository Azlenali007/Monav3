<?php
/**
 * Mona SMM Panel v2 - System Configuration
 * Strict PHP 8.2+ Architecture
 */

declare(strict_types=1);

// Prevent direct execution if required
if (!defined('MONA_SMM_PANEL')) {
    define('MONA_SMM_PANEL', true);
}

// Error reporting (Production safe: log errors, don't output raw trace to clients)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Timezone
date_default_timezone_set('UTC');

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'mona_smm');
define('DB_USER', getenv('DB_USER') ?: 'mona_user');
define('DB_PASS', getenv('DB_PASS') ?: 'mona_password');
define('DB_CHARSET', 'utf8mb4');

// System URLs and Information
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_URL', rtrim(getenv('APP_URL') ?: ($protocol . $host), '/'));
define('SITE_NAME', getenv('SITE_NAME') ?: 'Mona SMM Panel');
define('DEFAULT_CURRENCY', 'USD');

// Gateway Secret Encryption Key (Must be 32 bytes for AES-256-GCM)
// Stored in environment or securely configured per deployment
define('GATEWAY_ENC_KEY', getenv('GATEWAY_ENC_KEY') ?: 'm0na_smm_p@nel_enc_k3y_32byte__');

// Secure Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 days
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}
