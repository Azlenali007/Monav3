<?php
/**
 * Mona SMM Panel v2 - Asynchronous Announcement Dismissal
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$user = currentUser();
if (!$user) {
    http_response_code(401);
    exit;
}

$annId = (int)($_GET['id'] ?? 0);
if ($annId > 0) {
    $pdo = getDB();
    $pdo->prepare("
        INSERT IGNORE INTO user_announcement_dismissals (user_id, announcement_id, dismissed_at)
        VALUES (:uid, :aid, NOW())
    ")->execute([
        ':uid' => $user['id'],
        ':aid' => $annId
    ]);
}

echo json_encode(['status' => 'success']);
