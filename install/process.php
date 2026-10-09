<?php
/**
 * Mona SMM Panel v2 - Installation Processing Controller
 * Protected by installed.lock; non-destructive schema execution
 */

declare(strict_types=1);

if (file_exists(__DIR__ . '/installed.lock')) {
    header('Location: /index.php');
    exit;
}

// Ensure execution is only possible when lock is absent
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /install/index.php');
    exit;
}

$dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
$dbName = trim((string)($_POST['db_name'] ?? ''));
$dbUser = trim((string)($_POST['db_user'] ?? ''));
$dbPass = (string)($_POST['db_pass'] ?? '');

$adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
$adminEmail = trim((string)($_POST['admin_email'] ?? ''));
$adminPass = (string)($_POST['admin_pass'] ?? '');

if (empty($dbName) || empty($dbUser) || empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
    die("All database and administrator fields are required.");
}

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Apply baseline schema using CREATE TABLE IF NOT EXISTS
    $schemaFile = __DIR__ . '/../database/schema.sql';
    if (!file_exists($schemaFile)) {
        die("Schema file database/schema.sql is missing.");
    }

    $sql = file_get_contents($schemaFile);
    $pdo->exec($sql);

    // Apply Migration 004 to ensure all payment gateways and indexes are provisioned
    $mig4File = __DIR__ . '/../database/migrations/004_payment_gateways_and_fixes.sql';
    if (file_exists($mig4File)) {
        $pdo->exec(file_get_contents($mig4File));
    }

    // Provision Administrator Account
    $passwordHash = password_hash($adminPass, PASSWORD_BCRYPT);
    $apiKey = bin2hex(random_bytes(32));
    
    $adminStmt = $pdo->prepare("
        INSERT INTO users (username, email, password, role, status, api_key, balance, created_at)
        VALUES (:u, :e, :p, 'admin', 'active', :k, 1000.0000, NOW())
        ON DUPLICATE KEY UPDATE password = :p2, role = 'admin', status = 'active'
    ");
    $adminStmt->execute([
        ':u' => $adminUser,
        ':e' => $adminEmail,
        ':p' => $passwordHash,
        ':k' => $apiKey,
        ':p2' => $passwordHash
    ]);

    // Write installation lockfile
    file_put_contents(__DIR__ . '/installed.lock', "installed on " . date('Y-m-d H:i:s'));

    header('Location: /install/success.php');
    exit;

} catch (Exception $e) {
    die("Installation failure: " . htmlspecialchars($e->getMessage()));
}
