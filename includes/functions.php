<?php
/**
 * Mona SMM Panel v2 - Core Functions & Helpers
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Sanitize string input
 */
function cleanInput(?string $data): string {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency amount with symbol
 */
function formatCurrency(float $amount, string $currency = 'USD'): string {
    static $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'INR' => '₹',
        'GBP' => '£',
        'BRL' => 'R$',
        'USDT' => '₮'
    ];

    $symbol = $symbols[$currency] ?? ($currency . ' ');
    return $symbol . number_format($amount, 2, '.', ',');
}

/**
 * Time ago formatter
 */
function timeAgo(string $datetime): string {
    $time = strtotime($datetime);
    if (!$time) {
        return 'Never';
    }
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' mins ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $time);
}

/**
 * Outbound cURL provider API request with strict TLS and timeouts
 */
function callProviderApi(array $provider, array $params): array {
    if (empty($provider['api_url']) || empty($provider['api_key'])) {
        return ['error' => 'Provider API URL or key not configured'];
    }

    $params['key'] = $provider['api_key'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $provider['api_url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'MonaSMM/2.2 (PHP 8.2+ cURL Engine)'
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        error_log("Provider API cURL failure to {$provider['api_url']}: {$curlErr}");
        return ['error' => "Network connection to provider failed: {$curlErr}"];
    }

    if ($httpCode >= 400) {
        error_log("Provider API HTTP error {$httpCode}: {$response}");
        return ['error' => "Provider returned HTTP error status {$httpCode}"];
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return ['error' => 'Invalid JSON response received from provider API', 'raw' => $response];
    }

    return $json;
}

/**
 * Authenticated OpenSSL AES-256-GCM Encryption for Gateway Secrets
 */
function encryptGatewaySecret(string $plainText): string {
    if ($plainText === '') {
        return '';
    }
    
    $key = hash('sha256', GATEWAY_ENC_KEY, true);
    $ivLength = openssl_cipher_iv_length('aes-256-gcm');
    $iv = random_bytes($ivLength);
    $tag = '';
    
    $cipherText = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($cipherText === false) {
        throw new RuntimeException("Encryption failed");
    }

    return base64_encode($iv . $tag . $cipherText);
}

/**
 * Authenticated OpenSSL AES-256-GCM Decryption for Gateway Secrets
 */
function decryptGatewaySecret(string $payload): string {
    if ($payload === '') {
        return '';
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return '';
    }

    $key = hash('sha256', GATEWAY_ENC_KEY, true);
    $ivLength = openssl_cipher_iv_length('aes-256-gcm');
    
    if (strlen($decoded) < $ivLength + 16) {
        return '';
    }

    $iv = substr($decoded, 0, $ivLength);
    $tag = substr($decoded, $ivLength, 16);
    $cipherText = substr($decoded, $ivLength + 16);

    $decrypted = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $decrypted !== false ? $decrypted : '';
}

/**
 * Record an immutable transaction entry in transactions table
 */
function logTransaction(
    int $userId,
    string $type,
    float $amount,
    string $description,
    ?string $referenceId = null,
    float $fee = 0.0,
    string $currency = 'USD'
): int {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, type, amount, fee, currency, description, reference_id, created_at)
        VALUES (:user_id, :type, :amount, :fee, :currency, :description, :ref, NOW())
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':amount' => $amount,
        ':fee' => $fee,
        ':currency' => $currency,
        ':description' => $description,
        ':ref' => $referenceId
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Retrieve system setting with optional default
 */
function getSetting(string $key, ?string $default = null): ?string {
    static $settingsCache = null;
    $pdo = getDB();

    if ($settingsCache === null) {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settingsCache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return $settingsCache[$key] ?? $default;
}

/**
 * Update or insert system setting
 */
function setSetting(string $key, ?string $value): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES (:key, :val) 
        ON DUPLICATE KEY UPDATE setting_value = :val2
    ");
    $stmt->execute([
        ':key' => $key,
        ':val' => $value,
        ':val2' => $value
    ]);
}
