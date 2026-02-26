<?php
require_once __DIR__ . '/db.php';

function requireAdmin(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
        jsonError('Требуется авторизация', 401);
    }
    $token = $m[1];

    $db = getDB();
    // Удаляем просроченные сессии
    $db->exec("DELETE FROM admin_sessions WHERE expires_at < NOW()");

    $stmt = $db->prepare("SELECT id FROM admin_sessions WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    if (!$stmt->fetch()) {
        jsonError('Сессия истекла, войдите заново', 401);
    }
}
